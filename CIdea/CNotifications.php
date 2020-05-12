<?php

/**
 * Отправка периодических уведомлений
 * Class CNotifications
 */
class CNotifications
{
    public $companyData = [];
    private $ideaList = [];

    public function __construct()
    {
        require_once $_SERVER["DOCUMENT_ROOT"].'/bitrix/components/rarus/idea/class/CGraphs.php';
        require_once $_SERVER["DOCUMENT_ROOT"].'/bitrix/components/rarus/idea/class/CReports.php';
        require_once $_SERVER["DOCUMENT_ROOT"].'/bitrix/components/rarus/idea/class/CIdeaTools.php';
        CIdeaTools::init();
        $this->fillCompanyData();
    }

    private static function getLastDateStatusChange( $status_id )
    {
        global $DB;
        if(is_array($status_id) && !empty($status_id)) {
            $where = "`STATUS_ID` = ". implode(" OR `STATUS_ID` = ",$status_id) . " AND `ID` > 424115";
        } else {
            $where = "`STATUS_ID` =". intval($status_id);
        }

        $sql = "SELECT MIN(`LAST_STATUS_CHANGE`) AS `DATE` FROM `umsh_status_changes_table` WHERE {$where}" . " AND `ID` > 424115";

        try {
            if($res = $DB->Query($sql)) {
                $data = $res->Fetch();
                return  DateTime::createFromFormat( 'U', $data['DATE']);
                //return  date('Y.m.d', $data['DATE']);
            } else {
                return false;
            }
        } catch(Exception $e) {
            return false;
        }
    }

    public function getIdeaData($id)
    {
        CModule::IncludeModule("blog");
        $SORT = Array("DATE_PUBLISH" => "DESC", "NAME" => "ASC");
        $arFilter = Array(
            'ID' => $id
        );
        $SELECT = array("ID", "DATE_CREATE", "TITLE", "PUBLISH_STATUS", "AUTHOR_ID", "UF_AUTHOR_DEP", "UF_TRANSFER_DEP", "UF_USER_APP", "UF_USER_DEV" , "UF_STATUS");

        $dbPosts = CBlogPost::GetList(
            $SORT,
            $arFilter,
            false,
            false,
            $SELECT
        );

        while ($arPost = $dbPosts->Fetch())
        {
            $arPost['AUTHOR'] = CUser::GetByID($arPost['AUTHOR_ID'])->Fetch();
            $arPost['DEP_RESPONSIBLE'] = CIdeaTools::getBranchResponsibleUsers($arPost['UF_AUTHOR_DEP'])/*CIdeaTools::getBranchResponsibleUsersFromSettings($arPost['UF_AUTHOR_DEP'])*/;

            if(is_null($arPost['UF_TRANSFER_DEP'])) {
                $arPost['NEW_DEP_RESPONSIBLE'] = "";
                $arPost['NEW_DEP_RESPONSIBLE_NAMES'] = "";
            } else {
                $arPost['NEW_DEP_RESPONSIBLE'] = CIdeaTools::getBranchResponsibleUsers($arPost['UF_TRANSFER_DEP'])/*CIdeaTools::getBranchResponsibleUsersFromSettings($arPost['UF_TRANSFER_DEP'])*/;
                list($arPost['NEW_DEP_RESPONSIBLE_NAMES'], $nonOnuUsers) = self::getResponsibleNames($arPost['DEP_RESPONSIBLE']/*? + $arPost['NEW_DEP_RESPONSIBLE']*/);
            }

            $arPost['AUTHOR_EMAIL'] = (isset($arPost['AUTHOR']['EMAIL']))?$arPost['AUTHOR']['EMAIL']:"";

            // Ответственный за внедрение
            if(isset($arPost['UF_USER_APP']) && $unserialize = unserialize($arPost['UF_USER_APP'])) {
                $userArr = array();
                foreach ($unserialize as $code => $user) {
                    $rsUser = CUser::GetByID($user['USER']);
                    $arUser = $rsUser->Fetch();

                    $userArr[] = $arUser['EMAIL'];
                }
                $uf_user_app = implode(', ',$userArr);
            }
            $arPost['RESPONSIBLE_INTEGRATION_EMAIL'] = isset($uf_user_app)?$uf_user_app:"";

            // Ответственный за доработку
            if(isset($arPost['UF_USER_DEV']) && $unserialize = unserialize($arPost['UF_USER_DEV'])) {
                $userArr = array();
                foreach ($unserialize as $code => $user) {
                    $rsUser = CUser::GetByID($user['USER']);
                    $arUser = $rsUser->Fetch();

                    $userArr[] = $arUser['EMAIL'];
                }
                $uf_user_dev = implode(', ',$userArr);
            }
            $arPost['RESPONSIBLE_REWORK_EMAIL'] = isset($uf_user_dev)?$uf_user_dev:"";

            if( 1 == $arPost['UF_STATUS']/*если статус Новая- получаем email руководителя для эскалации*/ ) {
                //RE: Нарушен срок рассмотрения идеи 425597
                // 11.12.2018
                // Отправляем Руководителу ЭСП, с учетом передачи идеи
                if(is_null($arPost['UF_TRANSFER_DEP'])) {
                    $manager = self::getManager($arPost['UF_AUTHOR_DEP']);
                } else {
                    $manager = self::getManager($arPost['UF_TRANSFER_DEP']/*$arPost['UF_AUTHOR_DEP']*/);
                }

                $arPost['MANAGER'] = $manager? $manager['EMAIL']:"";/*TODO Руководитель ЭСП*/

                //TODO руководителю подавшего идею отправляем?
            }

            $arPost['DEP_RESPONSIBLE_EMAIL'] = "";

            if(!empty($arPost['DEP_RESPONSIBLE'])) {
                $arr_emails = [];
                $arr_names = [];
                foreach($arPost['DEP_RESPONSIBLE'] as $responsible) {
                    $arr_emails[] = $responsible['EMAIL'];
                    $arr_names[] = $responsible['NAME'] . " " . $responsible['SECOND_NAME'] . " " . $responsible['LAST_NAME'];
                }
                $arPost['DEP_RESPONSIBLE_EMAIL'] = implode(', ',$arr_emails);
                $arPost['DEP_RESPONSIBLE_NAMES'] = implode(', ',$arr_names);
            }
            $arPost['NEW_DEP_RESPONSIBLE_EMAIL'] = "";
            if(!empty($arPost['NEW_DEP_RESPONSIBLE'])) {
                $arr_emails = [];
                $arr_names = [];
                foreach($arPost['NEW_DEP_RESPONSIBLE'] as $responsible) {
                    $arr_emails[] =  $responsible['EMAIL'];
                    $arr_names[] =  $responsible['NAME'] . " " . $responsible['SECOND_NAME'] . " " . $responsible['LAST_NAME'];
                }
                $arPost['NEW_DEP_RESPONSIBLE_EMAIL'] = implode(', ',$arr_emails);
                $arPost['NEW_DEP_RESPONSIBLE_NAMES'] = implode(', ',$arr_names);
            }

            $arPost['IDEA_URL'] = 'http://msk03portal.sibur.local/services/idea/'.$arPost['ID'].'/';

            debugfile($arPost, "CNotifications.php", "/upload/debug/umsh/");

            return $arPost;
        }

        return false;
    }

    /**
     * @param $companyId int departmentID
     * @param $duration int количество дней в статусе Новая
     * @param string $limit_selection дата, начиная с которой выбираем идеи для рассылки # 12.12.2018
     * @return string
     * @throws Exception
     */
    public function notificationsInNewStatus($companyId, $duration, $limit_selection="01.09.2018")
    {
        global $DB;
        $dt = self::getLastDateStatusChange(1);
        $curDT = new DateTime('now');
        $diff = $curDT->diff($dt); //Количество дней между самой старой измененной датой и текущей датой
        $duration = intval($duration);

        if(!empty($limit_selection)) {
            $limit_selection = 'AND blog_post.DATE_CREATE >= "'.$limit_selection->format("Y-m-d H:i:s").'"';
        }

        if($companyId) {
            $companyId = intval($companyId);
            $limit_selection .= ' AND uts_blog_post.UF_AUTHOR_DEP = "'.$companyId.'" ';
        }


        /*Получаем список идей(неизменение статуса 45 дней) и проходимся по нему*/
        //$sql = "SELECT `ID` FROM `umsh_status_changes_table` WHERE `STATUS_ID` = 1 AND (`LAST_STATUS_CHANGE` = " . strtotime('today -'. $duration .' day'). ")  AND `ID` > 424115";
        $sql = "SELECT DISTINCT (status_changes.`ID`) FROM `umsh_status_changes_table` AS status_changes INNER JOIN `b_blog_post` AS blog_post ON blog_post.`ID` = status_changes.`ID`  INNER JOIN `b_uts_blog_post` AS uts_blog_post ON blog_post.`ID` = uts_blog_post.`VALUE_ID` WHERE status_changes.`STATUS_ID` = 1 $limit_selection AND (`LAST_STATUS_CHANGE` = " . strtotime('today -'. $duration .' day'). ")";

        //debugmessage($sql);

        try {
            if($res = $DB->Query($sql)) {
                while($data = $res->Fetch()) {
                    if(in_array($data['ID'], $this->ideaList)) break;
                    $this->ideaList[] = $data['ID'];
                    echo $data['ID']."<br>";
                    $data['notification'] = 1;
                    $data['idea'] = self::getIdeaData($data['ID']);
                    if(!empty($data['idea']))
                        self::sendNotification($data);
                }
            } else {
                return "Error 1!";
            }
        } catch(Exception $e) {
            return "Error 2!";
        }

        /*Получаем список идей и проходимся по нему*/
        $arrDaysTimestamp = array(); //Массив дат, по которым нужно выбрать идеи
        for( $days = ($duration-1); $days<= $diff->days; $days+=15){
            $arrDaysTimestamp[] = strtotime('today -'.$days.' day');
        }

        /*Получаем список идей и проходимся по нему*/
        if(!empty($arrDaysTimestamp)) {
            $where = implode(' OR `LAST_STATUS_CHANGE` = ', $arrDaysTimestamp);
            $sql = "SELECT DISTINCT (umsh_status.`ID`) FROM `umsh_status_changes_table` AS umsh_status INNER JOIN `b_blog_post` AS blog_post ON blog_post.`ID` = umsh_status.`ID` INNER JOIN `b_uts_blog_post` AS uts_blog_post ON blog_post.`ID` = uts_blog_post.`VALUE_ID` WHERE umsh_status.`STATUS_ID` = 1 AND (umsh_status.`LAST_STATUS_CHANGE` = {$where}) {$limit_selection}";

            try {
                if ($res = $DB->Query($sql)) {
                    while ($data = $res->Fetch()) {
                        if(in_array($data['ID'], $this->ideaList)) break;
                        $this->ideaList[] = $data['ID'];
                        echo $data['ID'] . "<br>";
                        $data['notification'] = 2;
                        $data['idea'] = self::getIdeaData($data['ID']);
                        if (!empty($data['idea']))
                            self::sendNotification($data);
                    }
                } else {
                    return "Error 1!";
                }
            } catch (Exception $e) {
                return "Error 2!";
            }
        }
    }

    public function notificationsInReworkStatus($companyId, $duration, $limit_selection="01.09.2018")
    {
        global $DB;
        $dt = self::getLastDateStatusChange(59);
        $curDT = new DateTime('now');
        $diff = $curDT->diff($dt); //Количество дней между самой старой измененной датой и текущей датой

        if(!empty($limit_selection)) {
            $limit_selection = 'AND blog_post.DATE_CREATE >= "'.$limit_selection->format("Y-m-d H:i:s").'"';
        }

        if($companyId) {
            $companyId = intval($companyId);
            $limit_selection .= ' AND uts_blog_post.UF_AUTHOR_DEP = "'.$companyId.'" ';
        }

        /*Получаем список идей(неизменение статуса 45 дней) и проходимся по нему*/
        $sql = "SELECT DISTINCT (status_changes.`ID`) FROM `umsh_status_changes_table` AS status_changes INNER JOIN `b_blog_post` AS blog_post ON blog_post.`ID` = status_changes.`ID`  INNER JOIN `b_uts_blog_post` AS uts_blog_post ON blog_post.`ID` = uts_blog_post.`VALUE_ID`  WHERE status_changes.`STATUS_ID` = 59 AND (status_changes.`LAST_STATUS_CHANGE` = " . strtotime('today -'. $duration/*TODO -1? */ .' day'). ") {$limit_selection}";

        try {
            if($res = $DB->Query($sql)) {
                while($data = $res->Fetch()) {
                    if(in_array($data['ID'], $this->ideaList)) break;
                    $this->ideaList[] = $data['ID'];
                    echo $data['ID']."<br>";
                    $data['notification'] = 3;
                    $data['idea'] = self::getIdeaData($data['ID']);

                    if(!empty($data['idea']))
                        self::sendNotification($data);
                }
            } else {
                return "Error 1!";
            }
        } catch(Exception $e) {
            return "Error 2!";
        }
        /*Получаем список идей и проходимся по нему*/
        $arrDaysTimestamp = array(); //Массив дат, по которым нужно выбрать идеи
        for( $days = ($duration-1); $days<= $diff->days; $days+=15){
            $arrDaysTimestamp[] = strtotime('today -'.$days.' day');
        }

        if(!empty($arrDaysTimestamp)) {
            /*Получаем список идей и проходимся по нему*/
            $where = implode(' OR status_changes.`LAST_STATUS_CHANGE` = ', $arrDaysTimestamp);

            $sql = "SELECT DISTINCT (status_changes.`ID`) FROM `umsh_status_changes_table` AS status_changes INNER JOIN `b_blog_post` AS blog_post ON blog_post.`ID` = status_changes.`ID`  INNER JOIN `b_uts_blog_post` AS uts_blog_post ON blog_post.`ID` = uts_blog_post.`VALUE_ID` WHERE status_changes.`STATUS_ID` = 59 AND (status_changes.`LAST_STATUS_CHANGE` = {$where}) {$limit_selection}";
            //foreach ($arrDaysTimestamp as $itemDay) {
            //$sql = "SELECT DISTINCT (status_changes.`ID`) FROM `umsh_status_changes_table` AS status_changes INNER JOIN `b_blog_post` AS blog_post ON blog_post.`ID` = status_changes.`ID`  INNER JOIN `b_uts_blog_post` AS uts_blog_post ON blog_post.`ID` = uts_blog_post.`VALUE_ID` WHERE status_changes.`STATUS_ID` = 59 AND (status_changes.`LAST_STATUS_CHANGE` = {$itemDay}) {$limit_selection}";
            try {
                if ($res = $DB->Query($sql)) {
                    while ($data = $res->Fetch()) {
                        if(in_array($data['ID'], $this->ideaList)) break;
                        $this->ideaList[] = $data['ID'];
                        echo $data['ID'] . "<br>";
                        $data['notification'] = 3;
                        $data['idea'] = self::getIdeaData($data['ID']);

                        if (!empty($data['idea']))
                            self::sendNotification($data);
                    }
                } else {
                    return "Error 1!";
                }
            } catch (Exception $e) {
                return "Error 2!";
            }
            //var_dump($sql);
            //}
        }
    }

    public function notificationsInWorkStatus($companyId, $duration, $limit_selection="01.09.2018")
    {
        $date_create = $limit_selection->format("d.m.Y");
        if(!empty($limit_selection)) {
            $limit_selection = 'AND blog_post.DATE_CREATE >= "'.$limit_selection->format("Y-m-d H:i:s").'"';
        }

        if($companyId) {
            $companyId = intval($companyId);
            $limit_selection .= ' AND uts_blog_post.UF_AUTHOR_DEP = "'.$companyId.'" ';
        }

        //Целевой срок реализации
        CModule::IncludeModule("blog");
        $SORT = Array("DATE_PUBLISH" => "DESC", "NAME" => "ASC");
        $arFilter = Array(
            'PUBLISH_STATUS' => BLOG_PUBLISH_STATUS_PUBLISH,
            'UF_STATUS' => 2,
            'UF_AUTHOR_DEP' => $companyId, /*TODO add UF_TRANSFER_DEP*/
            '>=DATE_CREATE' => $date_create.' 00:00:00'//?
        );
        $SELECT = array("ID", "DATE_CREATE", "TITLE", "PUBLISH_STATUS", "AUTHOR_ID", "UF_AUTHOR_DEP", "UF_TRANSFER_DEP","UF_DATE_ACCEPT", "UF_SOLUTION_DURATION");

        $dbPosts = CBlogPost::GetList(
            $SORT,
            $arFilter,
            false,
            false,
            $SELECT
        );

        debugfile($arFilter, 'testNotification08.19.log');

        while ($arPost = $dbPosts->Fetch())
        {
            if(in_array($arPost['ID'], $this->ideaList)) break;
            $this->ideaList[] = $arPost['ID'];
            // Парсим дату принятия в timestamp
            $acceptDate = explode('.', $arPost['UF_DATE_ACCEPT']);
            $acceptDate = mktime(0, 0, 0, $acceptDate[1], $acceptDate[0], $acceptDate[2]);

            // Расчитываем количество дней прошедших с даты подачи
            $diff = ceil((time() - $acceptDate) / (24 * 60 * 60)) - $arPost['UF_SOLUTION_DURATION'] /*+ 1*/;
            //echo $arPost['ID']. " " .$diff . "<br>";
            //debugmessage($arPost['ID']);
            if($diff >= $duration && $diff%5 == 0) {
                //Повторные запросы;( TODO передалать
                $data = [];
                $data['notification'] = 4;
                $data['idea'] = self::getIdeaData($arPost['ID']);

                if(!empty($data['idea']))
                    self::sendNotification($data);
            }
        }
    }

    private static function sendNotification($data) {
        $sMailEvent = 'UMSH_IDEA_NOTIFICATION';
        $arMailEventFields = $data['idea'];

        $dirname = "/upload/debug/umsh/".date("Y.m")."/";
        $filename = date("d")."_umshNotificationsOther.log";
        debugfile($arMailEventFields['ID'], '_'.$filename, $dirname);


        debugfile("--- ".date('H:i:s')." ---{", $filename, $dirname);
        debugfile(var_export($arMailEventFields,true), $filename, $dirname);
        debugfile(var_export($data['idea'],true), $filename, $dirname);
        debugfile("}--- end ---", $filename, $dirname);

        /*$arMailEventFields['RESPONSIBLE_INTEGRATION_EMAIL'] ="avramovav@tnhk.sibur.ru";
        $arMailEventFields['AUTHOR_EMAIL'] ="avramovav@tnhk.sibur.ru";
        $arMailEventFields['DEP_RESPONSIBLE_NAMES'] = var_export($arMailEventFields['DEP_RESPONSIBLE'],true);*/

        //debugmessage($arMailEventFields,'umshNotifyPeriodic.log');



        switch ($data['notification']) {
            case 1: // новая
                CEvent::SendImmediate(
                    $sMailEvent,
                    SITE_ID,
                    $arMailEventFields,
                    "N",
                    $notificationId = 554 // prod - 554 ; test - 548
                );
                break;
            case 2: // новая + руководитель
                CEvent::SendImmediate(
                    $sMailEvent,
                    SITE_ID,
                    $arMailEventFields,
                    "N",
                    $notificationId = 555 // prod - 555 ; test - 549
                );
                break;
            case 3: // на доработке
                CEvent::SendImmediate(
                    $sMailEvent,
                    SITE_ID,
                    $arMailEventFields,
                    "N",
                    $notificationId = 556 // prod - 556 ; test - 550
                );
                break;
            case 4: // в работе
                CEvent::SendImmediate(
                    $sMailEvent,
                    SITE_ID,
                    $arMailEventFields,
                    "N",
                    $notificationId = 557 // prod - 557 ; test - 551
                );
                break;
        }
    }

    private static function getResponsibleNames($responsible) {
        $responsibleNames = "";
        $responsibleNamesArr = [];
        $nonOnuUser = [];

        foreach ($responsible as $user) {
            // УМШ - Сотрудник ОНУ? группа 4155
            /*
            if(!in_array(4155, CUser::GetUserGroup($user['ID']))) {
                $rsUser = CUser::GetByID($user['ID']);
                $arUser = $rsUser->Fetch();
                $nonOnuUser[] = $arUser['EMAIL'];
                continue;
            }
            */
            // Во время ОПЭ отправляем только сотрудникам КЦ
            //if(CIdeaTools::corpCenterUser($user['ID'])) {
            $responsibleNamesArr[] = $user['NAME'] . " " . $user['SECOND_NAME'] . " " . $user['LAST_NAME'];
            //}
        }

        if(!empty($responsibleNamesArr)) {
            $responsibleNames = implode(', ', $responsibleNamesArr);
        }
        return [$responsibleNames, $nonOnuUser];
    }

    private static function getManager($departmentId) {
        $arrBranch = CIdeaTools::getArrayIdBranch($departmentId);
        $arFilter = array('IBLOCK_ID' => 3104, 'ID' => $arrBranch);
        $rsSections = CIBlockSection::GetList(array('DEPTH_LEVEL'=> 'DESC', 'LEFT_MARGIN' => 'ASC'), $arFilter, false, ['UF_BOSS', 'UF_NAME_FULL']);
        while ($arSection = $rsSections->Fetch())
        {
            if(!is_null($arSection['UF_BOSS'])) {
                return CUser::GetByID($arSection['UF_BOSS'])->Fetch();
            }
        }
        return false;
    }

    public function getCompanySettingsById($departmentId) {
        if(array_key_exists($departmentId, $this->companyData)) {
            return $this->companyData[$departmentId];
        }
        $result = [];
        $arFilter = array('IBLOCK_ID' => 3104, 'ID' => $this->getFirstLevelDepartmentId($departmentId));
        $rsSections = CIBlockSection::GetList(array('DEPTH_LEVEL'=> 'DESC', 'LEFT_MARGIN' => 'ASC'), $arFilter, false, ['UF_DURATION_IN_NEW', 'UF_DURATION_IN_REW', 'UF_DURATION_IN_WORK']);
        while ($arSection = $rsSections->Fetch())
        {
            $result['DURATION_IN_NEW'] = intval($arSection['UF_DURATION_IN_NEW']);
            $result['DURATION_IN_REW'] = intval($arSection['UF_DURATION_IN_REW']);
            $result['DURATION_IN_WORK'] = intval($arSection['UF_DURATION_IN_WORK']);
        }

        $this->companyData[$departmentId] = $result;
        return $result;
    }

    public function getFirstLevelDepartmentId($departmentId) {
        //UF_AUTHOR_DEP
        return CIdeaTools::getArrayIdBranch($departmentId)[0];
    }

    private function fillCompanyData() {
        $result = [];

        $arFilter = array('IBLOCK_ID' => 3104, 'DEPTH_LEVEL'=> 1);
        $rsSections = CIBlockSection::GetList(array('DEPTH_LEVEL'=> 'DESC', 'LEFT_MARGIN' => 'ASC'), $arFilter, false, ['UF_DURATION_IN_NEW', 'UF_DURATION_IN_REW', 'UF_DURATION_IN_WORK']);
        while ($arSection = $rsSections->Fetch())
        {
            $this->companyData[$arSection['ID']] = [
                'DURATION_IN_NEW' => intval($arSection['UF_DURATION_IN_NEW']),
                'DURATION_IN_REW' => intval($arSection['UF_DURATION_IN_REW']),
                'DURATION_IN_WORK' => intval($arSection['UF_DURATION_IN_WORK'])
            ];
            $result[] = $arSection;
        }

        return /*CIdeaTools::getBranch('all',1,false, false)*/ $result;
    }

    public function sendForAll() {
        foreach ($this->companyData as $companyId => $company) {
            if($company['DURATION_IN_NEW']>0)
                $this->notificationsInNewStatus(
                    $companyId,
                    $company['DURATION_IN_NEW'],
                    new \Bitrix\Main\Type\DateTime("22.02.2018 00:00:00")//"01.09.2019 00:00:00"
                );
            /*if($company['DURATION_IN_REW']>0)
            $this->notificationsInReworkStatus(
                $company['ID'],
                $company['DURATION_IN_REW']
            );

            if($company['DURATION_IN_WORK']>0)
            $this->notificationsInWorkStatus(
                $company['ID'],
                $company['DURATION_IN_WORK']
            );*/
        }
    }
}