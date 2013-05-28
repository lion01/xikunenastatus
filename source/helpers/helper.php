<?php 
/**
 * @copyright	Copyright (C) 2009 - 2013 Ready Bytes Software Labs Pvt. Ltd. All rights reserved.
 * @license		GNU/GPL, see LICENSE.php
 * @package		Payplans
 * @subpackage	KunenaStatus
 * @contact		team@reaybytes.in
 */
// no direct access
defined( '_JEXEC' ) or die( 'Restricted access' );

//XITODO : XiKunenaStatusHelper
class supportHelper
{
	public static function getCurrentStatus($topicId)
	{
		if(!$topicId){
			// XITODO : error
			$status = 'Topid Id not found';
			return $status; 
		}
				
		$record = self::_getTopic($topicId);
		
		switch ($record->current_status){
			case 0:
				$status = 'Open';
				break;
			case 1:
				$status = 'News';
				break;
			case 2:
				$status = 'Answered';
				break;
			case 3:
				$status = 'Wating';
				break;
			case 4:
				$status = 'Working';
				break;
			case 5:
				$status = 'Closed';
				break;
			case 6:
				$status = 'Roadmap';
				break;
			case 7:
				$status = 'Discuss';
				break;
			default:
				$status = 'Unknown';
		}
		
		return $status;
		
	}
	
	public static function xgetAuthor($threadId)
	{
		$db 	= JFactory::getDbo();
		$query	= "SELECT author_id FROM #__xi_kunena_status WHERE topic_id = '.$threadId.'";
		$db->setQuery($query);
		
		return $db->loadResult();	
	}

	public static function getModeratorsData($status, $time)
	{
		$db 	= JFactory::getDbo();
		$query	= "SELECT s.author_id, s.topic_id, s.current_status,  u.name, u.email, datediff(now(),  s.last_email_sent)
				  FROM #__xi_kunena_status as s LEFT JOIN #__users as u ON s.author_id = u.id
				  WHERE (current_status = ".$status.") AND datediff(now(),  s.last_email_sent) >=".$time;
		
		$db->setQuery($query);
		return $db->loadObjectList();
	}
	
	public function _getKunenaTopic($topic_id)
	{
		static $cache = null;
	
		if(!isset($cache[$topic_id])){
			$db = JFactory::getDbo();
			$selectQuery = 'SELECT * FROM #__kunena_topics WHERE `id` ='.$topic_id;
			$db->setQuery($selectQuery);
			$cache[$topic_id] = $db->loadObject();
		}
	
		return $cache[$topic_id];
	}
	
	public function _getTopic($topic_id)
	{
		static $cache = null;
	
		if(!isset($cache[$topic_id])){
			$db = JFactory::getDbo();
			$selectQuery = 'SELECT * FROM #__xi_kunena_status WHERE topic_id ='.$topic_id.'';
			$db->setQuery($selectQuery);
			$cache[$topic_id] = $db->loadObject();
		}
	
		return $cache[$topic_id];
	}
	
	public static function _updateKunenaStatus($ktopic, $newStatus)
	{
		$topic	= self::_getTopic($ktopic->id);
		$db 	= JFactory::getDbo();
		$query 	= 'UPDATE #__xi_kunena_status SET 
				  current_status = '.$newStatus.',
				  previous_status ='.$topic->current_status.' 
				  WHERE topic_id ='.$ktopic->id.'';
		
		$db->setQuery($query);
		return $db->query();
	}
	
	// SEND args instead of sending $table
	public static function _insertKunenaStatus($table)
	{
		$db		= JFactory::getDbo();
		$query	= 'INSERT INTO #__xi_kunena_status
				  (topic_id, previous_status, current_status, created_date, modified_date, last_email_sent, author_id)
				  VALUES ('.$table->thread.',0,0,now(),now(),now(), '.$table->userid.')';
		
		$db->setQuery($query);
		return $db->query();
	}
	
	public static function _updateCurrentStatus($table)
	{
		$db 			= JFactory::getDbo();
		
		// XITODO : use function _getTopic
		$selectQuery 	= 'SELECT current_status FROM #__xi_kunena_status WHERE topic_id ='.$table->thread.'';
		$db->setQuery($selectQuery);
		$currentStatus 	= $db->loadResult();
		$user = KunenaUserHelper::getMyself();
		
		if($currentStatus == 2 || $currentStatus == 3 && !$user->isModerator())
		{
			$query = 'UPDATE #__xi_kunena_status SET
					 current_status = 0,
		        	 modified_date = now(),
		        	 previous_status ='.$currentStatus.'
		        	 WHERE topic_id ='.$table->thread.'';
		
			$db->setQuery($query);
			$db->query();
		}
	}
	
	public static function _closeForums($closingTime)
	{
		// XITODO : Use defined array instead of number
		$db 	= JFactory::getDbo();
		$query 	= "UPDATE #__xi_kunena_status SET current_status = 5
			       WHERE (current_status = 2 or current_status = 3) && datediff(now(),modified_date) >= ".$closingTime;
		
		$db->setQuery($query);
		$db->query();
	}
	
	public static function _ownerMailData($waitingTime)
	{
		$db 	= JFactory::getDbo();
		$query	= "SELECT s.author_id, s.topic_id, s.current_status,  u.name, u.email, datediff(now(),  s.last_email_sent)
				  FROM #__xi_kunena_status as s LEFT JOIN #__users as u ON s.author_id = u.id
		          WHERE (current_status = 2 or current_status = 3) AND datediff(now(),  s.last_email_sent) >=".$waitingTime;
		
		$db->setQuery($query);
		return $db->loadObjectList();
	}
	
	public static function _createMailLog($emails, $subject)
	{
		$filename = dirname(__DIR__)."/maillogs.txt"; 
		$emails = implode(', ', $emails);
		$content=  "\n Date and time : ".date('Y-m-d H:i:s')."\n to : ".$emails."\n subject : ".$subject."\n";
		file_put_contents($filename, $content,FILE_APPEND);
	}
	
	public static function migration()
	{
		//collect data for xi_kunena_status table
		//insert one by one or in builk
		$db = JFactory::getDbo();
		$query = "SELECT first_post_id as topic_id, icon_id as current_status, first_post_time as created_date, 
				  last_post_time as modified_date, last_post_time as last_email_sent, first_post_userid as author_id FROM #__kunena_topics  ";
		$db->setQuery($query);
		$result = $db->loadObjectList();
		
		foreach($result as $data){
			$cdate  = new JDate($data->created_date);
			$mdate  = new JDate($data->modified_date);
			$query = "INSERT INTO #__xi_kunena_status SET 
					  topic_id 			= ".$data->topic_id.",
					  previous_status 	= -1,
					  current_status 	= ".$data->current_status.",
					  created_date		= '".$cdate->toSql()."',
					  modified_date		= '".$mdate->toSql()."', 
					  last_email_sent	= '".$mdate->toSql()."', 
					  author_id			= ".$data->author_id."";
			$db->setQuery($query);
			$db->query();
		}
	}
}
