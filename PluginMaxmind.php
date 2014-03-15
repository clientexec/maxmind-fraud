<?php
require_once 'modules/admin/models/FraudPlugin.php';
require_once 'library/CE/NE_Network.php';
require_once 'plugins/fraud/maxmind/maxmind_lib/CreditCardFraudDetection.php';
require_once 'library/CE/NE_MailGateway.php';

/**
* @package Plugins
*/
class PluginMaxmind extends FraudPlugin
{

    function getVariables()
    {
        $variables = array(
            /*T*/'Plugin Name'/*/T*/   => array(
                'type'          => 'hidden',
                'description'   => /*T*/''/*/T*/,
                'value'         => /*T*/'Maxmind'/*/T*/,
            ),
            /*T*/'Enabled'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting allows MaxMind customers to check orders for fraud.'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'MaxMind License Key'/*/T*/       => array(
                'type'          => 'text',
                'description'   => /*T*/'Enter your MaxMind License Key here.<br>You can obtain a license at <br><a href=http://www.maxmind.com/app/ccv_buynow?rId=clientexec target=_blank>http://www.maxmind.com/app/ccv_buynow</a>'/*/T*/,
                'value'         => '',
            ),
            /*T*/'Reject Free E-mail Service'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting allows you to reject any order using free E-mail services like Hotmail and Yahoo (free E-mail = higher risk).<br><b>NOTE: </b>Requires MaxMind'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'Reject Country Mismatch'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting allows you to reject any order where country of IP address does not match the billing address country (mismatch = higher risk).<br><b>NOTE: </b>Requires MaxMind'/*/T*/,
                'value'         => '1',
            ),
            /*T*/'Reject Anonymous Proxy'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting allows you to reject any order where the IP address is an Anonymous Proxy (anonymous proxy = very high risk).<br><b>NOTE: </b>Requires MaxMind'/*/T*/,
                'value'         => '1',
            ),
            /*T*/'Reject High Risk Country'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting allows you to reject any order where the country the IP is based from is considered a country where fraudulent order is likely.<br><b>NOTE: </b>Requires MaxMind'/*/T*/,
                'value'         => '0',
            ),
            /*T*/'MaxMind Fraud Risk Score'/*/T*/       => array(
                'type'          => 'text',
                'description'   => /*T*/'MaxMind risk score is based on known risk factors and their likelihood to indicate possible fraud. Select the threshold you want ClientExec to reject on. ( 0=low risk 100=high risk)<br><b>NOTE:</b> Requires MaxMind<br>To see how the fraud score is obtained visit <br><a href=http://www.maxmind.com/en/riskscore?rId=clientexec target=_blank>http://www.maxmind.com/en/riskscore</a>'/*/T*/,
                'value'         => 'none',
            ),
            /*T*/'MaxMind Warning E-mail'/*/T*/       => array(
                'type'          => 'textarea',
                'description'   => /*T*/'The E-mail address where a notification will be sent when the number of remaining queries reaches your MaxMind Low Query Threshold'/*/T*/,
                'value'         => '',
            ),
            /*T*/'MaxMind Low Query Threshold'/*/T*/       => array(
                'type'          => 'text',
                'description'   => /*T*/'A notification E-mail will be sent when the number of remaining queries reaches this value.'/*/T*/,
                'value'         => '10',
            ),
            /*T*/'Show MaxMind Logo'/*/T*/       => array(
                'type'          => 'yesno',
                'description'   => /*T*/'Setting this to YES will show the MaxMind fraud screening logo in the signup footer if credit card fraud detection or phone verification is turned on.'/*/T*/,
                'value'         => '1',
            ),
        );
    
        return $variables;
    }

    function grabDataFromRequest($request)
    {
        $ip = CE_Lib::getRemoteAddr();
        //get email custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typeEMAIL;
        $result = $this->db->query($query);
        list($tEmailID) = $result->fetch();
        //get city custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typeCITY;
        $result = $this->db->query($query);
        list($tCityID) = $result->fetch();
        //get state custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typeSTATE;
        $result = $this->db->query($query);
        list($tStateID) = $result->fetch();
        //get country custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typeCOUNTRY;
        $result = $this->db->query($query);
        list($tCountryID) = $result->fetch();
        //get zipcode custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typeZIPCODE;
        $result = $this->db->query($query);
        list($tZipcodeID) = $result->fetch();
        //get phone custom id for user
        $query = "SELECT id FROM customuserfields WHERE type=".typePHONENUMBER;
        $result = $this->db->query($query);
        list($tPhoneNumberID) = $result->fetch();

        $this->input["license_key"] = $this->settings->get('plugin_maxmind_MaxMind License Key');
        $this->input["i"] = $ip;                                                    // set the client ip address
        $this->input["city"] = $request['CT_'.$tCityID];                            // set the billing city
        $this->input["region"] = $request['CT_'.$tStateID];                         // set the billing state
        $this->input["postal"] = $request['CT_'.$tZipcodeID];                       // set the billing zip code
        $this->input["country"] = $request['CT_'.$tCountryID];                      // set the billing country
        $this->input["domain"] = mb_substr(strstr($request['CT_'.$tEmailID],'@'),1);
        $this->input["custPhone"] = $request['CT_'.$tPhoneNumberID];
        $this->input["emailMD5"] = $request['CT_'.$tEmailID];

        if (!is_null($this->settings->get("plugin_".@$_REQUEST['paymentMethod']."_Accept CC Number")) 
                && $this->settings->get("plugin_".@$_REQUEST['paymentMethod']."_Accept CC Number")) {
            $this->input["bin"] = mb_substr(@$_REQUEST[@$_REQUEST['paymentMethod'].'_ccNumber'],0,6);
        }
        $this->input["usernameMD5"] = md5(strtolower($request['CT_'.$tEmailID]));
        if (isset($request['new_password'])) {
            $this->input["passwordMD5"] = md5(strtolower($_REQUEST['password']));
        }
    }

    function execute()
    {
        // this can take a while
        @set_time_limit(0);

        $ccfs = new CreditCardFraudDetection;
        $ccfs->isSecure = 0;
        $ccfs->timeout = 5;
        $ccfs->input($this->input);
        $ccfs->query();
        $this->result = $ccfs->output();

        return $this->result;
    }

    function extraSteps()
    {
        // Only send a warning notification when number of queries matches the threshold to prevent sending the notification every time!
        if ($this->settings->get('plugin_maxmind_MaxMind Warning E-mail') != ''
            && $this->settings->get('plugin_maxmind_MaxMind Low Query Threshold') == $this->result['queriesRemaining'])
        {
            $mailGateway = new NE_MailGateway();
            $destinataries = explode("\r\n", $this->settings->get('MaxMind Warning E-mail'));
            foreach ($destinataries as $destinatary) {
                $mailGateway->mailMessageEmail( $this->user->lang("Dear Support Member,\r\n\r\nThis is a warning notification that your remaining MaxMind queries has reached your threshold of %s.\r\n\r\nThank you,\r\nClientExec", $this->settings->get('MaxMind Low Query Threshold')),
                                                $this->settings->get('Support E-mail'),
                                                $this->settings->get('Support E-mail'),
                                                $destinatary,
                                                0,
                                                $this->user->lang("WARNING: Low MaxMind Queries"));
            }
        }
    }

    function isOrderAccepted()
    {
        if ($this->settings->get('plugin_maxmind_MaxMind Fraud Risk Score') != 'none') {
            $tUserScore = floatval($this->settings->get('plugin_maxmind_MaxMind Fraud Risk Score'));
            $tScore = floatval($this->getRiskScore());

            if ($tScore>=$tUserScore) {
                $this->failureMessages[] = $this->user->lang('Your overall risk is too high, please contact our sales office for more information');
                return false;
            }
        }

        if (    isset($this->result['highRiskCountry'])
                && $this->result['highRiskCountry'] =="Yes"
                && $this->settings->get('plugin_maxmind_Reject High Risk Country') == 1)
        {
            $this->failureMessages[] = $this->user->lang('Sorry we are not accepting orders from your country');
        }

        if (    isset($this->result['countryMatch'])
                && $this->result['countryMatch'] =="No"
                && $this->settings->get('plugin_maxmind_Reject Country Mismatch') == 1)
        {
            $this->failureMessages[] = $this->user->lang('Your country does not match the IP you are currently signing up from');
        }

        if (    isset($this->result['freeMail'])
                && $this->result['freeMail'] =="Yes"
                && $this->settings->get('plugin_maxmind_Reject Free E-mail Service') == 1)
        {
            $this->failureMessages[] = $this->user->lang('We do not accept signups from free E-mail services');
        }

        if (    isset($this->result['anonymousProxy'])
                && $this->result['anonymousProxy'] =="Yes"
                && $this->settings->get('plugin_maxmind_Reject Anonymous Proxy') == 1)
        {
            $this->failureMessages[] = $this->user->lang('We do not accept signups from anonymous proxy servers');
        }

        if ($this->failureMessages) {
            return false;
        }

        return true;
    }
}


?>
