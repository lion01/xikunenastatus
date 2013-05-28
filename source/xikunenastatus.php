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

/**
 * Payplans AcyMailing Plugin
 *
 */

// get id of topic
// get new status
// rechk if logedin user is moderator 
class plgSystemXikunenastatus extends JPlugin
{
	public $status = array(  '0' => 'open',
							 '1' => 'news',
							 '2' => 'answered',
							 '3' => 'waiting',
							 '4' => 'working',
							 '5' => 'closed',
							 '6' => 'roadmap',
							 '7' => 'discuss');
	
	public function onAfterRoute()
	{	
		require_once __DIR__.'/helpers/helper.php';
		
		$app = JFactory::getApplication();
		
		if(strtolower($app->input->get('plugin')) === 'xikunenastatus'){
			

			$kunena_api = JPATH_ADMINISTRATOR.'/components/com_kunena/api.php';
			include_once ($kunena_api);	
			
			$action = $app->input->get('action');
			if($action ==='change_status'){				
				$topicId =  $app->input->get('topic_id');
				$status = $app->input->get('status');
				$ktopic = KunenaForumTopic::getInstance($topicId);
				$this->changeStatus($ktopic, $status);
			}
			
			if($action === 'run_cron'){
				$this->runCron();
			}
			
			if($action === 'migration'){
				$this->migration();
			}
			
			
			//XITODO : Use in Future for assigning moderators
			/**if($app->input->get('action')==='change_moderator'){
				$moderatorId =  $app->input->get('moderator_id');
				$this->changeModerator($topicId,$moderatorId);	
			}**/	
		}
	}
		
	
	public function _sendEmail( $emails, $subject, $message)
	{
		//when no email address exists
		if (empty($emails)){
			return true;
		}
		
		if(JDEBUG){
			supportHelper::_createMailLog($emails, $subject);
			return true;
		}

		$emails 	= is_array($emails) ? $emails : array($emails);
		$app  		= JFactory::getApplication();
		$mailfrom 	= $app->getCfg( 'mailfrom' );
		$fromname 	= $app->getCfg( 'fromname' );

		if( !$mailfrom  || !$fromname ) {
			throw new Exception(JText::_('COM_PAYINVOCIE_EXCEPTION_UTILS_NO_EMAILFROM_AND_FROMNAME_EXISTS'));
		}

		$message = html_entity_decode($message, ENT_QUOTES);
		$mail 	 = JFactory::getMailer()->setSender( array($mailfrom, $fromname))
									   	->addRecipient($emails)
							           	->setSubject($subject)
							           	->setBody($message);

		$mail->IsHTML(true);
				
		return $mail->Send();	
	}
	
	public function sendMailOnStatusChange($ktopic, $prev_status, $current_status)
	{
		$tmpl 			= 'email_'.$this->status[$current_status];  // tmpl will be email_news, email_discuss
		$email_data 	= array();
		$topic 			= supportHelper::_getTopic($ktopic->id);
		$user  			= JFactory::getUser($topic->author_id);
		$currentUser 	= JFactory::getUser();
		$doerName		= $currentUser->name;
		$kunena_topic 	= $ktopic;
		$forumUrl   	= $ktopic->getUri();

		$uri = JUri::getInstance("index.php?option=com_kunena&view=topic&catid={$ktopic->category_id}&id={$ktopic->id}");
		$uri = KunenaRoute::_($uri);
		$forumlink= JUri::getInstance()->toString(array('scheme', 'host', 'port')). $uri;
		
		$subject 		= $kunena_topic->subject;
		$file = dirname(__FILE__).'/tmpl/'.$tmpl.'.php';
		ob_start();
		include $file;
		$body = ob_get_contents();
		ob_clean();
		
		
		$to = array($user->email, $this->params->get('forum_email')); // add moderator
		 $this->_sendEmail($to, $subject, $body);
		 JFactory::getApplication()->redirect(html_entity_decode($forumlink));
	}
	
	public function changeStatus($ktopic,$newStatus)
	{
		//get topic id and new status from argument & get current state of topic & update current and previous status
		
		$result = supportHelper::_updateKunenaStatus($ktopic, $newStatus);
		if($result){
			$this->sendMAilOnStatusChange($ktopic, $topic->current_status, $newStatus);
		}
		else{
			//XITODO : failure
		}	
	}
	
	public function onKunenaAfterSave($entity, $table, $isNew){
		if($entity !== 'com_kunena.KunenaForumMessage')
			return true;
		
		//if(!$isNew && ($session->get('is_data')==='yes')){
		if($isNew && empty($table->parent)){
			supportHelper::_insertKunenaStatus($table);	
		}
		
		if($isNew && $table->parent){
				
			supportHelper::_updateCurrentStatus($table);
		}
	}
	
	public function runCron()
	{
		$closingTime = $this->params->get('closingTime');
		supportHelper::_closeForums($closingTime);
		$this->_mailToOwner();
		$this->_mailToModerator(4, $this->params->get('moderatorTime'));
		$this->_mailToModerator(0, 1);
		//for roadmap mails
		$this->_mailToModerator(6, $this->params->get('reminderTime'));
	}
	
	public function _mailToOwner()
	{
		$waitingTime 	= $this->params->get('waitingTime');
		$result			= supportHelper::_ownerMailData($waitingTime);
		
		foreach ($result as $email_data){
			$this->sendMailToUserOnCron($email_data);
		}		
	}
	
	public function sendMailToUserOnCron($email_data)
	{
		$tmpl 			= 'email_'.$this->status[$email_data->current_status];
		$ktopic			= supportHelper::_getKunenaTopic($email_data->topic_id);
		$doerName		= "autometed";
		$user  			= JFactory::getUser($email_data->author_id);
		$current_status	= $email_data->current_status;
		$uri = JUri::getInstance("index.php?option=com_kunena&view=topic&catid={$ktopic->category_id}&id={$ktopic->id}");
		$uri = KunenaRoute::_($uri);
		$forumlink= JUri::getInstance()->toString(array('scheme', 'host', 'port')). $uri;
		
		$subject 		= $ktopic->subject;
		$file = dirname(__FILE__).'/tmpl/'.$tmpl.'.php';
		ob_start();
		include $file;
		$body = ob_get_contents();
		ob_clean();
		
		$to = array($user->email, $this->params->get('forum_email')); // add moderator
		$this->_sendEmail($to, $subject, $body);
	}
	

	
	public function _mailToModerator($status, $time)
	{
		$result  = supportHelper::getModeratorsData($status, $time);
		foreach ($result as $email_data){
			$this->_sendMailToModeratorOnCron($email_data);
		}
	}
	
	public function _sendMailToModeratorOnCron($email_data)
	{
		$tmpl 			= 'email_'.$this->status[$email_data->current_status];
		$ktopic			= supportHelper::_getKunenaTopic($email_data->topic_id);
		$doerName		= "automated";
		
		//$user  			= JFactory::getUser($email_data->author_id);
		$current_status	= $email_data->current_status;
		$uri = JUri::getInstance("index.php?option=com_kunena&view=topic&catid={$ktopic->category_id}&id={$ktopic->id}");
		$uri = KunenaRoute::_($uri);
		$forumlink= JUri::getInstance()->toString(array('scheme', 'host', 'port')). $uri;
	
		$subject 		= $ktopic->subject;
		$file = dirname(__FILE__).'/tmpl/'.$tmpl.'.php';
		ob_start();
		include $file;
		$body = ob_get_contents();
		ob_clean();
	
		$to = array($this->params->get('forum_email')); // add moderator
		$this->_sendEmail($to, $subject, $body);
	}
		
// 	public function changeModerator($topicId,$moderatorId){
// 		//get topic id 
// 		//get new moderator
// 		//update moderator
		
// 		$db = JFactory::getDbo();
// 		$q = 'UPDATE #__xi_kunena_status SET assigned_to =' .$moderatorId.'WHERE topic_id ='.$topicId.'';
// 		$db->setQuery($q);
// 		$db->query();
// 	}
	
	public function migration(){
		supportHelper::migration();
	}
	
}

