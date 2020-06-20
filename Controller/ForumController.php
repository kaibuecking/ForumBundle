<?php
/**
 * Created by PhpStorm.
 * User: cro
 * Date: 15.09.17
 * Time: 15:46
 */

namespace con4gis\ForumBundle\Controller;


use con4gis\CoreBundle\Resources\contao\classes\C4GUtils;
use con4gis\ForumBundle\Resources\contao\models\C4gForumPn;
use con4gis\ForumBundle\Resources\contao\modules\C4GForum;
use Contao\FrontendUser;
use Contao\Input;
use Contao\ModuleModel;
use Contao\System;
use Contao\Database;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use con4gis\ForumBundle\Resources\contao\classes\C4GForumHelper;
use con4gis\ForumBundle\Resources\contao\models\C4gForumSession;

class ForumController extends Controller
{
    public function ajaxAction(Request $request, $id, $req = '')
    {
        $response = new JsonResponse();
        $post = $request->request->get('post');
        if ($post) {
            $post = Input::xssClean($post);
            $post = C4GUtils::cleanHtml($post);
            $post = C4GUtils::secure_ugc($post);
            $request->request->set('post', $post);
        }
        $feUser = FrontendUser::getInstance();
        $feUser->authenticate();
        if (!isset( $id ) || !is_numeric( $id )) {
            $response->setStatusCode(400);
        }
        if (!strlen($id) || $id < 1)
        {
            $response->setData('Missing frontend module ID');
            $response->setStatusCode(412);
        }
        $objModule = ModuleModel::findByPk($id);

        if (!$objModule)
        {
            $response->setData('Frontend module not found');
            $response->setStatusCode(404);
        }

        // Show to guests only
        if ($objModule->guests && FE_USER_LOGGED_IN && !BE_USER_LOGGED_IN && !$objModule->protected)
        {
            $response->setData('Forbidden');
            $response->setStatusCode(403);
        }

        // Protected element
        if (!BE_USER_LOGGED_IN && $objModule->protected)
        {
            if (!FE_USER_LOGGED_IN)
            {
                $response->setData('Forbidden');
                $response->setStatusCode(403);
            }
            $groups = deserialize($objModule->groups);
            if (!is_array($groups) || count($groups) < 1 || count(array_intersect($groups, $feUser->groups)) < 1)
            {
                $response->setData('Forbidden');
                $response->setStatusCode(403);
            }
        }

        // Return if the class does not exist
        if (!class_exists(C4GForum::class))
        {
//            $this->log('Module class "'.$GLOBALS['FE_MOD'][$objModule->type].'" (module "'.$objModule->type.'") does not exist', 'Ajax getFrontendModule()', TL_ERROR);
            $response->setData('Frontend module class does not exist');
            $response->setStatusCode(404);
        }

        $objModule->typePrefix = 'mod_';
        $objModule = new C4GForum($objModule);
        $return = $objModule->generateAjax($req, $feUser);
        $response->setData($return);
        return $response;
    }

    public function personalMessageAction(Request $request, $actionFragment)
    {
        $response = new JsonResponse();
        $feUser = FrontendUser::getInstance();
        $feUser->authenticate();
        if (!FE_USER_LOGGED_IN || !$_COOKIE["FE_USER_AUTH"]) {
            $response->setStatusCode(400);
            return $response;
        }
        $arrFragments = explode('/', $actionFragment);
        System::loadLanguageFile("tl_c4g_forum_pn");
        try {
            // check which service is requested
            switch($arrFragments[0]) {
                case "modal":
                    if (!empty($arrFragments[1])) {
                        $sType      = $arrFragments[1];
                        $aReturn    = array();
                        $sClassName = "con4gis\\ForumBundle\\Resources\\contao\\classes\\" . ucfirst($sType);
                        if (class_exists($sClassName)) {
                            $aData = \Input::get('data');

                            $aReturn['template'] = $sClassName::parse($aData);
                        }
                        $response->setData($aReturn);
                        return $response;
                    } else {
                        $response->setStatusCode(400);
                        return $response;
                    }
                    break;
                case "delete":
                    $iId = $arrFragments[1];
                    $oPn = C4gForumPn::getById($iId);
                    $res = $oPn->delete();
                    $response->setData(['success' => $res]);
                    return $response;
                    break;
                case "mark":
                    $iStatus = intval(\Input::post('status'));
                    $iId = intval(\Input::post('id'));

                    $oPn = C4gForumPn::getById($iId);
                    $oPn->setStatus($iStatus);
                    $oPn->update();
                    $response->setData(['success' => true]);
                    return $response;
                    break;
                case "markAll":
                    $recipient_id = \Input::get('recipient');
                    $count = $this->markAllMessages($recipient_id, $feUser->id);
                    $response->setData(['count' => $count, 'success' => true]);
                    return $response;
                    break;
                case "send":
                    $iRecipientId = \Input::post('recipient_id');
                    $sRecipient = \Input::post('recipient');
                    $sUrl = \Input::post('url');
                    if (empty($iRecipientId) && !empty($sRecipient)) {
                        $aRecipient = C4gForumPn::getMemberByUsername($sRecipient);
                        if(empty($aRecipient)){
                            throw new \Exception($GLOBALS['TL_LANG']['tl_c4g_forum_pn']['member_not_found']);
                        }
                        $iRecipientId = $aRecipient['id'];
                    }

                    // sanitize message from malicious tags. A and BR are allowed!
                    // $message = strip_tags($_POST['message'], '<a><br>');
                    $message = $_POST['message'];

                    // \Input::post('subject')
                    $subject = "Nachricht";

                    $aData = array(
                        "subject"      => $subject,
                        "message"      => htmlentities($message),
                        "sender_id"    => $feUser->id,
                        "recipient_id" => $iRecipientId,
                        "dt_created"   => time(),
                        "status"       => 0
                    );
                    $oPn = C4gForumPn::create($aData);
                    $oPn->send($sUrl);
                    $response->setData(['success' => true, 'message' => html_entity_decode($aData['message'])]);
                    return $response;
                    break;
                case "messages":
                    $recipient_id = \Input::get('recipient');
                    $offset = \Input::get('offset');
                    if ($recipient_id) {
                        $response->setData($this->getMessages($feUser->id, $recipient_id, $offset));
                    } else {
                        $response->setStatusCode(404);
                    }
                    return $response;
                    break;
                case "contact":
                    $username = \Input::get('username');
                    $userId = \Input::get('id');
                    if ($userId) {
                        $response->setData($this->getContactbyId($userId));
                    } 
                    elseif($username) {
                        $response->setData($this->getContactbyUsername($username));    
                    }    
                    else{
                        $response->setStatusCode(404);
                    }    
                    return $response;
                    break;    
                default:
                    $response->setStatusCode(400);
                    return $response;
                    break;
            }
        } catch (\Exception $e) {
            $response->setData(['success' => false, "message" => $e->getMessage()]);
            return $response;
        }
    }

    private function getMessages($sender_id, $recipient_id, $offset) {
		
		/*$sql = "SELECT COUNT(tl_c4g_forum_pn.id) AS Count  FROM tl_c4g_forum_pn WHERE (sender_id = ? OR recipient_id = ?) AND (sender_id = ? OR recipient_id = ?) ";
		$messages = Database::getInstance()->prepare($sql)->execute($sender_id, $sender_id, $recipient_id, $recipient_id);
		
		$count = $messages->Count;
		$limit = 5;
		
		$offset_sql = $count - $limit;*/
		
		/*
		if($count > $limit)
		{
			$offset_sql = $count - $limit;
		}
		else 
		{
			$offset_sql = 0;
			$limit = $count;
		}*/
		
		$offset_sql = intval($offset);
		$limit = 500;
				
		if ($sender_id == $recipient_id) {
			$sql = "SELECT message, sender_id, dt_created, subject FROM tl_c4g_forum_pn WHERE sender_id = ? AND recipient_id = ?	ORDER BY dt_created ASC	LIMIT 5 OFFSET 0";
		} else {
			$sql = "SELECT message, sender_id, dt_created, subject FROM tl_c4g_forum_pn WHERE (sender_id = ? OR recipient_id = ?) AND (sender_id = ? OR recipient_id = ?) ORDER BY dt_created DESC LIMIT ? OFFSET ?"; 
		}

		$messages = Database::getInstance()->prepare($sql)->execute($sender_id, $sender_id, $recipient_id, $recipient_id, $limit, $offset_sql);
		
		if ($messages->numRows < 1) {
				return ;
		}
		$arrMessages = array();
		
		while ($messages->next()) {
				
			$message['message'] = html_entity_decode($messages->message);
            //$message['message'] = html_entity_decode($messages->message) . '-'.$count. '-'. $offset;
			$message['sender_id'] = $messages->sender_id;
            $message['creation_day'] = date('d.m.y', $messages->dt_created);
            $message['creation_time'] = date('H:i', $messages->dt_created);		
            $message['dt_created'] = $messages->dt_created;		
            $message['subject'] = $messages->subject;	
			array_push($arrMessages, $message);
		}
        
        if($offset_sql == 0)
        {
            // Hole eine Liste von Spalten
            foreach ($arrMessages as $key => $row) {
                $dt_created[$key]    = $row['dt_created'];
            }

            // von PHP 5.5.0 an kann array_column() statt des obigen Codes verwendet werden
            $dt_created  = array_column($arrMessages, 'dt_created');
            array_multisort($dt_created, SORT_ASC, SORT_STRING, $arrMessages);
        }
		return $arrMessages;
    }
	
	public function sortArrayByFields($arr, $fields)
	{
		$sortFields = array();
		$args       = array();

		foreach ($arr as $key => $row) {
			foreach ($fields as $field => $order) {
				$sortFields[$field][$key] = $row[$field];
			}
		}

		foreach ($fields as $field => $order) {
			$args[] = $sortFields[$field];

			if (is_array($order)) {
				foreach ($order as $pt) {
					$args[$pt];
				}
			} else {
				$args[] = $order;
			}
		}

		$args[] = &$arr;

		call_user_func_array('array_multisort', $args);

		return $arr;
	}


    private function getContactbyId($userId){

        $sql = "SELECT username, memberImage FROM tl_member WHERE id = ?  ";
        $sql= $sql . $GLOBALS['TL_CONFIG']['EXCLUDE_USER'];
		$result = Database::getInstance()->prepare($sql)->execute($userId );
		
		if ($result->numRows != 1) {
				return ;
        }

		$arrUser = array();
		$result->next();				
        $user['username'] = $result->username;
        $user['memberImage'] = C4GForumHelper::getAvatarByMemberId($userId, array(75, 75));
        if($user['memberImage'] == null){
            $user['memberImage'] =  'files/themes/images/header/default_avatar.png';
        }
        $user['onlineStatus'] = C4gForumSession::getOnlineStatusByMemberId($userId);     	
        array_push($arrUser, $user);    
            	
		return $arrUser;
    }

    private function getContactbyUsername($username){

        $username.= '%';
        $sql = "SELECT id, username, memberImage FROM tl_member WHERE username COLLATE UTF8_GENERAL_CI LIKE ? ";
        $sql = $sql . $GLOBALS['TL_CONFIG']['EXCLUDE_USER'];
        $sql = $sql . " ORDER BY username LIMIT 5";
       $result = Database::getInstance()->prepare($sql)->execute($username );
       
        if ($result->numRows < 1) {
               return ;
        }
        $arrUsers = array();
        while ($result->next()) {				
            $user['id'] = $result->id;
            $user['username'] = $result->username;
            $user['memberImage'] = C4GForumHelper::getAvatarByMemberId($result->id, array(75, 75));	
            if($user['memberImage'] == null){
                $user['memberImage'] = 'files/themes/images/header/default_avatar.png';
            }
            $user['onlineStatus'] = C4gForumSession::getOnlineStatusByMemberId($result->id); 
            array_push($arrUsers, $user);    
       }        
       return $arrUsers;
   }
   
    public function getMessageCount($recipient_id) {
			$sql = "SELECT 
						COUNT(message) AS MessageCount
					FROM
						tl_c4g_forum_pn
					WHERE
						recipient_id = ? AND status = 0 ";
							
		$messages = Database::getInstance()->prepare($sql)->execute($recipient_id);
		
		if ($messages->numRows < 1) {
				return ;
		}
		$arrMessages = array();
		
		while ($messages->next()) {
				
			$MessageCount = $messages->MessageCount;
		}
		
		return $MessageCount;
    }
	
	private function markAllMessages($sender_id, $recipient_id) {
			$sql = "UPDATE 
						tl_c4g_forum_pn SET status = 1
					WHERE
						recipient_id = ? AND sender_id = ? AND status = 0"; 
							
		Database::getInstance()->prepare($sql)->execute($recipient_id, $sender_id);
		
		//Die Anzahl noch offener Nachrichten ermitteln
		$MessageCount = $this->getMessageCount($recipient_id); 
		return $MessageCount; 
    }
}