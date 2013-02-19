<?php
	require_once(dirname(__FILE__) . "/class.pmprogateway.php");
	class PMProGateway_payflowpro
	{
		function PMProGateway_payflowpro($gateway = NULL)
		{
			$this->gateway = $gateway;
			return $this->gateway;
		}										
		
		function process(&$order)
		{
			if(floatval($order->InitialPayment) == 0)
			{
				//auth first, then process
				$authorization_id = $this->authorize($order);					
				if($authorization_id)
				{
					$this->void($order, $authorization_id);						
					$order->ProfileStartDate = date("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod)) . "T0:0:0";
					$order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
					return $this->subscribe($order);
				}
				else
				{
					if(empty($order->error))
						$order->error = "Unknown error: Authorization failed.";
					return false;
				}
			}
			else
			{
				//charge first payment
				if($this->charge($order))
				{																							
					//setup recurring billing
					if(pmpro_isLevelRecurring($order->membership_level))
					{
						$order->ProfileStartDate = date("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod)) . "T0:0:0";
						$order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
						if($this->subscribe($order))
						{							
							return true;
						}
						else
						{							
							if($this->refund($order, $order->payment_transaction_id))
							{
								if(empty($order->error))
									$order->error = "Unknown error: Payment failed.";							
							}
							else
							{
								if(empty($order->error))
									$order->error = "Unknown error: Payment failed.";
								
								$order->error .= " A partial payment was made that we could not refund. Please contact the site owner immediately to correct this.";
							}
							
							return false;	
						}
					}
					else
					{
						//only a one time charge							
						$order->status = "success";	//saved on checkout page											
						$order->saveOrder();
						return true;
					}
				}								
			}	
		}
		
		function authorize(&$order)
		{
			if(empty($order->code))
				$order->code = $order->getRandomCode();
									
			//paypal profile stuff
			$nvpStr = "";
						
			$nvpStr .="&AMT=1.00";			
			$nvpStr .= "&NOTIFYURL=" . urlencode(admin_url('admin-ajax.php') . "?action=ipnhandler");
			//$nvpStr .= "&L_BILLINGTYPE0=RecurringPayments&L_BILLINGAGREEMENTDESCRIPTION0=" . $order->PaymentAmount;
			
			$nvpStr .= "&CUSTIP=" . $_SERVER['REMOTE_ADDR'] . "&INVNUM=" . $order->code;
						
			//credit card fields
			if($order->cardtype == "American Express")
				$cardtype = "Amex";
			else
				$cardtype = $order->cardtype;
			
			if(!empty($accountnumber))
				$nvpStr .= "&ACCT=" . $order->accountnumber . "&EXPDATE=" . $order->ExpirationDate . "&CVV2=" . $order->CVV2;
			
			//billing address, etc
			if(!empty($order->Address1))
			{
				$nvpStr .= "&EMAIL=" . $order->Email . "&FIRSTNAME=" . $order->FirstName . "&LASTNAME=" . $order->LastName . "&STREET=" . $order->Address1;
				
				if($order->Address2)
					$nvpStr .= " " . $order->Address2;
				
				$nvpStr .= "&CITY=" . $order->billing->city . "&STATE=" . $order->billing->state . "&BILLTOCOUNTRY=" . $order->billing->country . "&ZIP=" . $order->billing->zip . "&PHONENUM=" . $order->billing->phone;
			}

			//for debugging, let's attach this to the class object
			$this->nvpStr = $nvpStr;
			
			$this->httpParsedResponseAr = $this->PPHttpPost('A', $nvpStr);
			
			krumo($this);
						
			if("SUCCESS" == strtoupper($this->httpParsedResponseAr["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($this->httpParsedResponseAr["ACK"])) {
				$order->authorization_id = $this->httpParsedResponseAr['TRANSACTIONID'];
				$order->updateStatus("authorized");				
				return $order->authorization_id;				
			} else  {				
				$order->status = "error";
				$order->errorcode = $this->httpParsedResponseAr['L_ERRORCODE0'];
				$order->error = urldecode($this->httpParsedResponseAr['L_LONGMESSAGE0']);
				$order->shorterror = urldecode($this->httpParsedResponseAr['L_SHORTMESSAGE0']);
				return false;				
			}					
		}
		
		function void(&$order, $authorization_id)
		{
			if(empty($authorization_id))
				return false;
			
			//paypal profile stuff
			$nvpStr="&ORIGID=" . $authorization_id;
		
			$this->httpParsedResponseAr = $this->PPHttpPost('C', $nvpStr);							
									
			if("SUCCESS" == strtoupper($this->httpParsedResponseAr["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($this->httpParsedResponseAr["ACK"])) {				
				return true;				
			} else  {				
				$order->status = "error";
				$order->errorcode = $this->httpParsedResponseAr['L_ERRORCODE0'];
				$order->error = urldecode($this->httpParsedResponseAr['L_LONGMESSAGE0']);
				$order->shorterror = urldecode($this->httpParsedResponseAr['L_SHORTMESSAGE0']);
				return false;				
			}	
		}					
		
		function charge(&$order)
		{
			global $pmpro_currency;
			
			if(empty($order->code))
				$order->code = $order->getRandomCode();
			
			//taxes on the amount
			$amount = $order->InitialPayment;
			$amount_tax = $order->getTaxForPrice($amount);						
			$order->subtotal = $amount;
			$amount = round((float)$amount + (float)$amount_tax, 2);
			
			//paypal profile stuff
			$nvpStr = "";			
			$nvpStr .="&AMT=" . $amount . "&TAXAMT=" . $amount_tax;			
			$nvpStr .= "&NOTIFYURL=" . urlencode(admin_url('admin-ajax.php') . "?action=ipnhandler");
			//$nvpStr .= "&L_BILLINGTYPE0=RecurringPayments&L_BILLINGAGREEMENTDESCRIPTION0=" . $order->PaymentAmount;
			
			$nvpStr .= "&CUSTIP=" . $_SERVER['REMOTE_ADDR'] . "&INVNUM=" . $order->code;			
			
			if(!empty($order->accountnumber))
				$nvpStr .= "&ACCT=" . $order->accountnumber . "&EXPDATE=" . $order->ExpirationDate . "&CVV2=" . $order->CVV2;
			
			//billing address, etc
			if($order->Address1)
			{
				$nvpStr .= "&EMAIL=" . $order->Email . "&FIRSTNAME=" . $order->FirstName . "&LASTNAME=" . $order->LastName . "&STREET=" . $order->Address1;
				
				if($order->Address2)
					$nvpStr .= " " . $order->Address2;
				
				$nvpStr .= "&CITY=" . $order->billing->city . "&STATE=" . $order->billing->state . "&BILLTOCOUNTRY=" . $order->billing->country . "&ZIP=" . $order->billing->zip . "&PHONENUM=" . $order->billing->phone;
			}

			$this->httpParsedResponseAr = $this->PPHttpPost('S', $nvpStr);
			
			krumo($this);
						
			if("SUCCESS" == strtoupper($this->httpParsedResponseAr["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($this->httpParsedResponseAr["ACK"])) {
				$order->payment_transaction_id = $this->httpParsedResponseAr['TRANSACTIONID'];
				$order->updateStatus("success");				
				return true;				
			} else  {				
				$order->status = "error";
				$order->errorcode = $this->httpParsedResponseAr['L_ERRORCODE0'];
				$order->error = urldecode($this->httpParsedResponseAr['L_LONGMESSAGE0']);
				$order->shorterror = urldecode($this->httpParsedResponseAr['L_SHORTMESSAGE0']);				
				return false;				
			}								
		}
		
		function subscribe(&$order)
		{
			die("Recurring subscriptions are not currently supported by Paid Memberships Pro");
		}	
		
		function update(&$order)
		{
			die("Updated billing is not currently supported by Paid Memberships Pro");
		}
		
		function cancel(&$order)
		{
			//require a subscription id
			if(empty($order->subscription_transaction_id))
				return false;
			
			//simulate a successful cancel			
			$order->updateStatus("cancelled");					
			return true;
		}	
		
		/**
		 * PAYPAL Function
		 * Send HTTP POST Request
		 *
		 * @param	string	The API method name
		 * @param	string	The POST Message fields in &name=value pair format
		 * @return	array	Parsed HTTP Response body
		 */
		function PPHttpPost($methodName_, $nvpStr_) {
			global $gateway_environment;
			$environment = $gateway_environment;	
		
			$PARTNER = pmpro_getOption("payflow_partner");
			$VENDOR = pmpro_getOption("payflow_vendor");
			$USER = pmpro_getOption("payflow_user");
			$PWD = pmpro_getOption("payflow_pwd");
			$API_Endpoint = "https://payflowpro.paypal.com";
			if("sandbox" === $environment || "beta-sandbox" === $environment) {
				$API_Endpoint = "https://pilot-payflowpro.paypal.com";
			}						
			
			$version = urlencode('4');
		
			// setting the curl parameters.
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $API_Endpoint);
			curl_setopt($ch, CURLOPT_VERBOSE, 1);
		
			// turning off the server and peer verification(TrustManager Concept).
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POST, 1);
		
			// NVPRequest for submitting to server
			$nvpreq = "TRXTYPE=" . $methodName_ . "&TENDOR=C&PARTNER=" . $PARTNER . "&VENDOR=" . $VENDOR . "&USER=" . $USER . "&PWD=" . $PWD . $nvpStr_;
					
			// setting the nvpreq as POST FIELD to curl
			curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);
		
			// getting response from server
			$httpResponse = curl_exec($ch);
		
			if(empty($httpResponse)) {
				exit("$methodName_ failed: ".curl_error($ch).'('.curl_errno($ch).')');
			}
		
			// Extract the RefundTransaction response details
			$httpResponseAr = explode("&", $httpResponse);
		
			$httpParsedResponseAr = array();
			foreach ($httpResponseAr as $i => $value) {
				$tmpAr = explode("=", $value);
				if(sizeof($tmpAr) > 1) {
					$httpParsedResponseAr[$tmpAr[0]] = $tmpAr[1];
				}
			}
		
			if((0 == sizeof($httpParsedResponseAr)) || !array_key_exists('ACK', $httpParsedResponseAr)) {
				exit("Invalid HTTP Response for POST request($nvpreq) to $API_Endpoint.");
			}
		
			return $httpParsedResponseAr;
		}
	}
?>