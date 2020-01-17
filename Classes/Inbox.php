<?php
/*
 * This file is part of con4gis,
 * the gis-kit for Contao CMS.
 *
 * @package    con4gis
 * @version    7
 * @author     con4gis contributors (see "authors.txt")
 * @license    LGPL-3.0-or-later
 * @copyright  Küstenschmiede GmbH Software & Design
 * @link       https://www.con4gis.org
 */
    namespace con4gis\ForumBundle\Classes;

    use con4gis\ForumBundle\Resources\contao\models\C4gForumPn;
    use Contao\FrontendUser;

    /**
     * Class Inbox
     * @package con4gis\ForumBundle\Classes
     */
    class Inbox
    {
        protected static $sTemplate = 'modal_inbox';

        public static function parse()
        {
            $oUser = FrontendUser::getInstance();
            $aPns = C4gForumPn::getByRecipient($oUser->id);

            $oTemplate = new \FrontendTemplate(self::$sTemplate);
            $oTemplate->pns = $aPns;

            return $oTemplate->parse();
        }
    }