<?php

require_once 'modules/admin/models/FraudPlugin.php';

use MaxMind\MinFraud;

class PluginMaxmind extends FraudPlugin
{
    private $response;

    function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => '',
                'value' => lang('Maxmind'),
            ],
            lang('Enabled') => [
                'type' => 'yesno',
                'description' => lang('Setting allows MaxMind customers to check orders for fraud.'),
                'value' => '0',
            ],
            lang('MaxMind Account ID') => [
                'type'  => 'text',
                'description' => lang('Enter your MaxMind Account ID. <br>You can obtain a license at <a href=https://www.maxmind.com/en/solutions/minfraud-services?rId=clientexec target=_blank>https://www.maxmind.com/en/solutions/minfraud-services</a>'),
                'value' => '',
            ],
            lang('MaxMind License Key') => [
                'type' => 'text',
                'description' => lang('Enter your MaxMind License Key here.'),
                'value' => '',
            ],
            lang('Enable Device Tracking Addon') => [
                'type' => 'yesno',
                'description' => lang('The device tracking add-on identifies devices as they move across networks and enhances the ability of the minFraud services to detect fraud.'),
                'value' => '1',
            ],
            lang('Service') => [
                'type' => 'options',
                'description' => lang('Select the MaxMind Service you would like to use.  More information on service types is available <a href="https://www.maxmind.com/en/solutions/minfraud-services" target="_blank">here</a>.'),
                'options' => ['Score' => 'Score', 'Insights' => 'Insights', 'Factors' => 'Factors'],
                'value' => 'Score',
            ],

            lang('Reject Free E-mail Service') => [
                'type' => 'yesno',
                'description' => lang('Enabling this setting will reject any order using a free email service, such as gmail, hotmail, or yahoo.<br><b>NOTE:</b> Requires Insights or Factors Service'),
                'value' => '0',
            ],
            lang('Reject Country Mismatch') => [
                'type' => 'yesno',
                'description' => lang('Enabling this setting will reject any order where the billing address country does not match the country the IP address is registered to.<br><b>NOTE:</b> Requires Insights or Factors Service.'),
                'value' => '1',
            ],
            lang('Reject Anonymous Proxy') => [
                'type' => 'yesno',
                'description' => lang('Enabling this setting will reject any order where the IP address is an Anonymous Proxy.<br><b>NOTE:</b> Requires Insights or Factors Service'),
                'value' => '1',
            ],
            lang('MaxMind Fraud Risk Score') => [
                'type' => 'text',
                'description' => lang('The minFraud service evaluates your transactions against billions of scored transactions from the minFraud network, drawing on machine learning as well as years of expert review to provide a numerical indicator of risk.  Any Risk Score higher then this number will be rejected.'),
                'value' => 'none',
            ],
            lang('MaxMind Warning E-mail') => [
                'type' => 'textarea',
                'description' => lang('The email address where a notification will be sent when the number of remaining queries reaches your MaxMind Low Query Threshold'),
                'value' => '',
            ],
            lang('MaxMind Low Query Threshold') => [
                'type' => 'text',
                'description' => lang('A notification email will be sent when the number of remaining queries reaches this value.'),
                'value' => '10',
            ],
        ];

        return $variables;
    }

    private function getCustomFieldId($customFieldType)
    {
        $query = 'SELECT id FROM customuserfields WHERE type = ' . $customFieldType;
        $result = $this->db->query($query);
        list($id) = $result->fetch();

        return $id;
    }

    function grabDataFromRequest($request)
    {
        $ip = CE_Lib::getRemoteAddr();

        $emailId = $this->getCustomFieldId(typeEMAIL);
        $firstNameId = $this->getCustomFieldId(typeFIRSTNAME);
        $lastNameId = $this->getCustomFieldId(typeLASTNAME);
        $addressId = $this->getCustomFieldId(typeADDRESS);
        $cityId = $this->getCustomFieldId(typeCITY);
        $stateId = $this->getCustomFieldId(typeSTATE);
        $countryId = $this->getCustomFieldId(typeCOUNTRY);
        $zipCodeId = $this->getCustomFieldId(typeZIPCODE);
        $phoneNumberId = $this->getCustomFieldId(typePHONENUMBER);
        $companyId = $this->getCustomFieldId(typeORGANIZATION);

        $this->input['client']['first_name'] = $request['CT_' . $firstNameId];
        $this->input['client']['last_name'] = $request['CT_' . $lastNameId];
        $this->input['client']['company'] = $request['CT_' . $companyId];
        $this->input['client']['address'] = $request['CT_' . $addressId];
        $this->input['client']['city'] = $request['CT_' . $cityId];
        $this->input['client']['state'] = $request['CT_' . $stateId];
        $this->input['client']['country'] = $request['CT_' . $countryId];
        $this->input['client']['zip_code'] = $request['CT_' . $zipCodeId];
        $this->input['client']['phone_number'] = $request['CT_' . $phoneNumberId];
        $this->input['client']['payment_method'] = $request['paymentMethod'];

        $this->input['order']['totalPay_raw'] = $request['totalPay_raw'];

        $this->input['ip'] = $ip;
        $this->input['client']['email']['address'] = $request['CT_' . $emailId];
        $this->input['client']['email']['domain'] = mb_substr(strstr($request['CT_' . $emailId], '@'), 1);

        if (!is_null($this->settings->get("plugin_".@$request['paymentMethod']."_Accept CC Number")) && $this->settings->get("plugin_".@$request['paymentMethod']."_Accept CC Number")) {
            $this->input["bin"] = mb_substr(@$request[@$request['paymentMethod'].'_ccNumber'], 0, 6);
        }

        $this->input['session_id'] = session_id();
        $this->input['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        $this->input['accept_language'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    }

    function execute()
    {
        $mf = new MinFraud($this->getVariable('MaxMind Account ID'), $this->getVariable('MaxMind License Key'));
        $request = $mf->withDevice([
            'ip_address' => $this->input['ip'],
            'session_id' => $this->input['session_id'],
            'user_agent' => $this->input['user_agent'],
            'accept_language' => $this->input['accept_language'],
        ])->withEmail([
            'address' => $this->input['client']['email']['address'],
            'domain' => $this->input['client']['email']['domain'],
        ])->withBilling([
            'first_name' => $this->input['client']['first_name'],
            'last_name' => $this->input['client']['last_name'],
            'company' => $this->input['client']['company'],
            'address' => $this->input['client']['address'],
            'city' => $this->input['client']['city'],
            // Can not send region until we support states from a database.
            //'region' => $this->input['client']['state'],
            'country' => $this->input['client']['country'],
            'postal' => $this->input['client']['zip_code'],
            'phone_number' => $this->input['client']['phone_number'],
        ]);
        if ($this->input['order']['totalPay_raw'] > 0) {
            $request->withOrder([
                'amount' => $this->input['order']['totalPay_raw'],
                'currency' => $this->settings->get('Default Currency')
            ]);
        }

        $service = strtolower($this->settings->get('plugin_maxmind_Service'));
        $result = $request->$service();

        $this->response = $result;
        $this->result['MaxMind ID'] = $result->id;
        $this->result['riskScore'] = $result->riskScore;
        $this->result['queriesRemaining'] = $result->queriesRemaining;

        if (isset($result->billingAddress)) {
            $this->result['Country IP Match'] = $this->yesNo($result->billingAddress->isInIpCountry);
        }
        if (isset($result->email)) {
            $this->result['Free Email'] = $this->yesNo($result->email->isFree);
            $this->result['High Risk Email'] = $this->yesNo($result->email->isHighRisk);
        }
        if (isset($result->ipAddress->traits)) {
            $ipTraits = $result->ipAddress->traits;
            $this->result['ISP'] = $ipTraits->isp;
            $this->result['Public Proxy'] = $this->yesNo($ipTraits->isPublicProxy);
            $this->result['Tor Exit Node'] = $this->yesNo($ipTraits->isTorExitNode);
            $this->result['Anonymous Network'] = $this->yesNo($ipTraits->isAnonymous);
            $this->result['Anonymous Vpn'] = $this->yesNo($ipTraits->isAnonymousVpn);
            $this->result['Hosting Provider'] = $this->yesNo($ipTraits->isHostingProvider);
        }

        return $this->result;
    }

    function extraSteps()
    {
        // Only send a warning notification when number of queries matches the threshold to prevent sending the notification every time!
        if ($this->settings->get('plugin_maxmind_MaxMind Warning E-mail') != '' && $this->settings->get('plugin_maxmind_MaxMind Low Query Threshold') == $this->result['queriesRemaining']) {
            $mailGateway = new NE_MailGateway();
            $destinataries = explode("\r\n", $this->settings->get('MaxMind Warning E-mail'));
            foreach ($destinataries as $destinatary) {
                $mailGateway->mailMessageEmail(
                    $this->user->lang("Dear Support Member") . ",\r\n\r\n"
                    . sprintf($this->user->lang('This is a warning notification that your remaining MaxMind queries has reached your threshold of %s.'), $this->settings->get('MaxMind Low Query Threshold'))
                    . "\r\n\r\n"
                    . $this->user->lang('Thank you')
                    . ",\r\nClientExec",
                    $this->settings->get('Support E-mail'),
                    $this->settings->get('Support E-mail'),
                    $destinatary,
                    0,
                    $this->user->lang("WARNING: Low MaxMind Queries")
                );
            }
        }
    }

    function isOrderAccepted()
    {
        $service = $this->settings->get('plugin_maxmind_Service');
        // Always check risk score
        if ($this->settings->get('plugin_maxmind_MaxMind Fraud Risk Score') != 'none') {
            $tUserScore = floatval($this->settings->get('plugin_maxmind_MaxMind Fraud Risk Score'));
            $tScore = floatval($this->getRiskScore());

            if ($tScore >= $tUserScore) {
                $this->failureMessages[] = $this->user->lang('Your overall risk is too high, please contact our sales office for more information');
                return false;
            }
        }

        if ($service == 'Insights' || $service == 'Factors') {
            // Reject Country to IP Mismatch?
            if ($this->settings->get('plugin_maxmind_Reject Country Mismatch') == 1) {
                if ($this->response->billingAddress->isInIpCountry === false) {
                    $this->failureMessages[] = $this->user->lang('Your country does not match the IP you are currently signing up from');
                }
            }

            // Reject Free Email?
            if ($this->settings->get('plugin_maxmind_Reject Free E-mail Service') == 1) {
                if ($this->response->email->isFree === true) {
                    $this->failureMessages[] = $this->user->lang('We do not accept signups from free email providers');
                }
            }

            // Reject Anonymous Proxy?
            if ($this->settings->get('plugin_maxmind_Reject Anonymous Proxy') == 1) {
                if ($this->response->ipAddress->traits->isAnonymousProxy === true) {
                    $this->failureMessages[] = $this->user->lang('We do not accept signups from anonymous proxy servers');
                }
            }
        }

        if ($this->failureMessages) {
            return false;
        }

        return true;
    }

    private function yesNo($bool)
    {
        return ($bool === true ? 'Yes' : 'No');
    }
}
