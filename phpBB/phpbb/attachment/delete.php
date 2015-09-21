<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\attachment;

use \phpbb\config\config;
use \phpbb\db\driver\driver_interface;
use \phpbb\event\dispatcher;

/**
 * Attachment delete class
 */

class delete
{
	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\event\dispatcher */
	protected $dispatcher;

	/** @var \phpbb\attachment\resync */
	protected $resync;

	/** @var array Attachement IDs */
	protected $ids;

	/** @var string SQL ID string */
	private $sql_id;

	/** @var string SQL where string */
	private $sql_where = '';

	/** @var int Number of deleted items */
	private $num_deleted;

	/** @var array Post IDs */
	private $post_ids = array();

	/** @var array Message IDs */
	private $message_ids = array();

	/** @var array Topic IDs */
	private $topic_ids = array();

	/** @var array Info of physical file */
	private $physical = array();

	/**
	 * Attachment delete class constructor
	 *
	 * @param config $config
	 * @param driver_interface $db
	 * @param dispatcher $dispatcher
	 * @param resync $resync
	 */
	public function __construct(config $config, driver_interface $db, dispatcher $dispatcher, resync $resync)
	{
		$this->config = $config;
		$this->db = $db;
		$this->dispatcher = $dispatcher;
		$this->resync = $resync;
	}

	/**
	 * Delete Attachments
	 *
	 * @param string $mode can be: post|message|topic|attach|user
	 * @param mixed $ids can be: post_ids, message_ids, topic_ids, attach_ids, user_ids
	 * @param bool $resync set this to false if you are deleting posts or topics
	 *
	 * @return int|bool Number of deleted attachments or false if something
	 *			went wrong during attachment deletion
	 */
	public function delete($mode, $ids, $resync = true)
	{
		if (!$this->set_attachment_ids($ids))
		{
			return false;
		}

		$this->set_sql_constraints($mode);

		/**
		 * Perform additional actions before collecting data for attachment(s) deletion
		 *
		 * @event core.delete_attachments_collect_data_before
		 * @var	string	mode			Variable containing attachments deletion mode, can be: post|message|topic|attach|user
		 * @var	mixed	ids				Array or comma separated list of ids corresponding to the mode
		 * @var	bool	resync			Flag indicating if posts/messages/topics should be synchronized
		 * @var	string	sql_id			The field name to collect/delete data for depending on the mode
		 * @since 3.1.7-RC1
		 */
		$vars = array(
			'mode',
			'ids',
			'resync',
			'sql_id',
		);
		extract($this->dispatcher->trigger_event('core.delete_attachments_collect_data_before', compact($vars)));

		// Collect post and topic ids for later use if we need to touch remaining entries (if resync is enabled)
		$this->collect_attachment_info($resync);

		// Delete attachments from database
		$this->delete_attachments_from_db();

		/**
		 * Perform additional actions after attachment(s) deletion from the database
		 *
		 * @event core.delete_attachments_from_database_after
		 * @var	string	mode			Variable containing attachments deletion mode, can be: post|message|topic|attach|user
		 * @var	mixed	ids				Array or comma separated list of ids corresponding to the mode
		 * @var	bool	resync			Flag indicating if posts/messages/topics should be synchronized
		 * @var	string	sql_id			The field name to collect/delete data for depending on the mode
		 * @var	array	post_ids		Array with post ids for deleted attachment(s)
		 * @var	array	topic_ids		Array with topic ids for deleted attachment(s)
		 * @var	array	message_ids		Array with private message ids for deleted attachment(s)
		 * @var	array	physical		Array with deleted attachment(s) physical file(s) data
		 * @var	int		num_deleted		The number of deleted attachment(s) from the database
		 * @since 3.1.7-RC1
		 */
		$vars = array(
			'mode',
			'ids',
			'resync',
			'sql_id',
			'post_ids',
			'topic_ids',
			'message_ids',
			'physical',
			'num_deleted',
		);
		extract($this->dispatcher->trigger_event('core.delete_attachments_from_database_after', compact($vars)));

		if (!$this->num_deleted)
		{
			return 0;
		}

		// Delete attachments from filesystem
		$this->delete_attachments_from_filesystem();

		// If we do not resync, we do not need to adjust any message, post, topic or user entries
		if (!$resync)
		{
			return $this->num_deleted;
		}

		// No more use for the original ids
		unset($ids);

		// Update post indicators for posts now no longer having attachments
		$this->resync->resync('post', $this->post_ids);

		// Update message table if messages are affected
		$this->resync->resync('message', $this->message_ids);

		// Now update the topics. This is a bit trickier, because there could be posts still having attachments within the topic
		$this->resync->resync('topic', $this->topic_ids);

		return $this->num_deleted;
	}

	/**
	 * Set attachment IDs
	 *
	 * @param array $ids
	 *
	 * @return bool True if attachment IDs were set, false if not
	 */
	protected function set_attachment_ids($ids)
	{
		// 0 is as bad as an empty array
		if (empty($ids))
		{
			return false;
		}

		if (is_array($ids))
		{
			$ids = array_unique($ids);
			$this->ids = array_map('intval', $ids);
		}
		else
		{
			$this->ids = array((int) $ids);
		}

		return true;
	}

	/**
	 * Set SQL constraints based on mode
	 *
	 * @param string $mode Delete mode; can be: post|message|topic|attach|user
	 */
	private function set_sql_constraints($mode)
	{
		switch ($mode)
		{
			case 'post':
			case 'message':
				$this->sql_id = 'post_msg_id';
				$this->sql_where = ' AND in_message = ' . ($mode == 'message' ? 1 : 0);
			break;

			case 'topic':
				$this->sql_id = 'topic_id';
			break;

			case 'user':
				$this->sql_id = 'poster_id';
			break;

			case 'attach':
			default:
				$this->sql_id = 'attach_id';
			break;
		}
	}

	/**
	 * Collect info about attachment IDs
	 *
	 * @param bool $resync Whether topics/posts should be resynced after delete
	 */
	protected function collect_attachment_info($resync)
	{
		// Collect post and topic ids for later use if we need to touch remaining entries (if resync is enabled)
		$sql = 'SELECT post_msg_id, topic_id, in_message, physical_filename, thumbnail, filesize, is_orphan
			FROM ' . ATTACHMENTS_TABLE . '
			WHERE ' . $this->db->sql_in_set($this->sql_id, $this->ids);

		$sql .= $this->sql_where;

		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			// We only need to store post/message/topic ids if resync is enabled and the file is not orphaned
			if ($resync && !$row['is_orphan'])
			{
				if (!$row['in_message'])
				{
					$this->post_ids[] = $row['post_msg_id'];
					$this->topic_ids[] = $row['topic_id'];
				}
				else
				{
					$this->message_ids[] = $row['post_msg_id'];
				}
			}

			$this->physical[] = array('filename' => $row['physical_filename'], 'thumbnail' => $row['thumbnail'], 'filesize' => $row['filesize'], 'is_orphan' => $row['is_orphan']);
		}
		$this->db->sql_freeresult($result);

		// IDs should be unique
		$this->post_ids = array_unique($this->post_ids);
		$this->message_ids = array_unique($this->message_ids);
		$this->topic_ids = array_unique($this->topic_ids);
	}

	/**
	 * Delete attachments from database table
	 */
	protected function delete_attachments_from_db()
	{
		/**
		 * Perform additional actions before attachment(s) deletion
		 *
		 * @event core.delete_attachments_before
		 * @var	string	mode			Variable containing attachments deletion mode, can be: post|message|topic|attach|user
		 * @var	mixed	ids				Array or comma separated list of ids corresponding to the mode
		 * @var	bool	resync			Flag indicating if posts/messages/topics should be synchronized
		 * @var	string	sql_id			The field name to collect/delete data for depending on the mode
		 * @var	array	post_ids		Array with post ids for deleted attachment(s)
		 * @var	array	topic_ids		Array with topic ids for deleted attachment(s)
		 * @var	array	message_ids		Array with private message ids for deleted attachment(s)
		 * @var	array	physical		Array with deleted attachment(s) physical file(s) data
		 * @since 3.1.7-RC1
		 */
		$vars = array(
			'mode',
			'ids',
			'resync',
			'sql_id',
			'post_ids',
			'topic_ids',
			'message_ids',
			'physical',
		);
		extract($this->dispatcher->trigger_event('core.delete_attachments_before', compact($vars)));

		// Delete attachments
		$sql = 'DELETE FROM ' . ATTACHMENTS_TABLE . '
			WHERE ' . $this->db->sql_in_set($this->sql_id, $this->ids);

		$sql .= $this->sql_where;

		$this->db->sql_query($sql);
		$this->num_deleted = $this->db->sql_affectedrows();
	}

	/**
	 * Delete attachments from filesystem
	 */
	protected function delete_attachments_from_filesystem()
	{
		$space_removed = $files_removed = 0;

		foreach ($this->physical as $file_ary)
		{
			if (phpbb_unlink($file_ary['filename'], 'file', true) && !$file_ary['is_orphan'])
			{
				// Only non-orphaned files count to the file size
				$space_removed += $file_ary['filesize'];
				$files_removed++;
			}

			if ($file_ary['thumbnail'])
			{
				phpbb_unlink($file_ary['filename'], 'thumbnail', true);
			}
		}

		/**
		 * Perform additional actions after attachment(s) deletion from the filesystem
		 *
		 * @event core.delete_attachments_from_filesystem_after
		 * @var	string	mode			Variable containing attachments deletion mode, can be: post|message|topic|attach|user
		 * @var	mixed	ids				Array or comma separated list of ids corresponding to the mode
		 * @var	bool	resync			Flag indicating if posts/messages/topics should be synchronized
		 * @var	string	sql_id			The field name to collect/delete data for depending on the mode
		 * @var	array	post_ids		Array with post ids for deleted attachment(s)
		 * @var	array	topic_ids		Array with topic ids for deleted attachment(s)
		 * @var	array	message_ids		Array with private message ids for deleted attachment(s)
		 * @var	array	physical		Array with deleted attachment(s) physical file(s) data
		 * @var	int		num_deleted		The number of deleted attachment(s) from the database
		 * @var	int		space_removed	The size of deleted files(s) from the filesystem
		 * @var	int		files_removed	The number of deleted file(s) from the filesystem
		 * @since 3.1.7-RC1
		 */
		$vars = array(
			'mode',
			'ids',
			'resync',
			'sql_id',
			'post_ids',
			'topic_ids',
			'message_ids',
			'physical',
			'num_deleted',
			'space_removed',
			'files_removed',
		);
		extract($this->dispatcher->trigger_event('core.delete_attachments_from_filesystem_after', compact($vars)));

		if ($space_removed || $files_removed)
		{
			$this->config->increment('upload_dir_size', $space_removed * (-1), false);
			$this->config->increment('num_files', $files_removed * (-1), false);
		}
	}
}
