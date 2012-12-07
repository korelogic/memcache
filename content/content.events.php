<?php
	/**
	 * This extension requires the `pager` extension to be installed and active. This is used
	 * to generate standardized pagination for some of the management pages. This can be 
	 * obtained from Github:
	 *
	 * @link https://github.com/wilhelm-murdoch/pager
	 */
	try {
		if(Symphony::ExtensionManager()->fetchStatus('pager') !== EXTENSION_ENABLED) {
			throw new Exception;
		}
	} catch(Exception $Exception) {
		throw new SymphonyErrorPage(__('This extension requires the `Pager` extension. You can find it here %s', array('<a href="https://github.com/wilhelm-murdoch/pager">github.com/wilhelm-murdoch/pager</a>')));
	}

	/**
	 * Libraries:
	 */
	require_once EXTENSIONS.'/memcache/lib/class.process.php';
	require_once EXTENSIONS.'/pager/lib/class.pager.php';
	require_once TOOLKIT.'/class.administrationpage.php';
	require_once TOOLKIT.'/class.sectionmanager.php';

	class ContentExtensionMemcacheEvents extends AdministrationPage {
	
		public function __construct(Administration &$parent) {
			parent::__construct($parent);

			$SectionManager = new SectionManager($this->_Parent);

			$this->sectionNamesArray = array();
			foreach($SectionManager->fetch(NULL, 'ASC', 'sortorder') as $Section) {
				$this->sectionNamesArray[$Section->get('id')] = $Section->get('name');
			}
		}
		
		/**
		 * Displays the index page within the Symphony administration panel.
		 */
		public function __viewIndex() {
			if(isset($this->_context[1]) && $this->_context[1] == 'removed') {
				$this->pageAlert(__('MemCache has been removed!'), Alert::SUCCESS);
			}

			$this->setPageType('table');
			$this->setTitle(__(
				'%1$s &ndash; %2$s',
				array(
					__('Symphony'),
					__('Memcache')
				)
			));

			$this->appendSubheading(__('<a href="'.Extension_Memcache::baseUrl()."/cache".'">Memcache</a> &rarr; Events'));

			
			$cachesTableHead = array(
					array(__('Event'),      'col'),
					array(__('Type'),      'col'),
					array(__('Topic'),      'col'),
					array(__('Message'),    'col'),
					array(__('Process'),    'col'),
					array(__('Received'),   'col'),
			);

			$totalCaches = array_pop(Symphony::Database()->fetch("SELECT COUNT(1) AS count FROM `sym_extensions_memcache_events`"));

			$Pager = Pager::factory($totalCaches['count'], Symphony::Configuration()->get('pagination_maximum_rows', 'symphony'), 'pg');

			$events = Symphony::Database()->fetch('
				SELECT
					`id`,
					`label`,
					`type`,
					`topic`,
					`message`,
					`received`,
					`process_id`
				FROM `sym_extensions_memcache_events`
				ORDER BY `id` DESC '.$Pager->getLimit(true)
			);

			$cachesTableBody = array();
			if(false == $events) {
				$cachesTableBody[] = Widget::TableRow(array(
						Widget::TableData(__('None found.'), 'inactive', NULL, count($cachesTableHead))
					)
				);
			} else foreach($events as $event) {

				$process = new Process();
				$process->setPid($event['process_id']);
				
				$status = $process->status();

				$cachesTableBody[] = Widget::TableRow(array(
						//$labelRow,
						Widget::TableData($event['label']),
						Widget::TableData($event['type']),
						Widget::TableData($event['topic']),
						Widget::TableData($event['message']),
						Widget::TableData((bool) $status ? "Priming ". $status[2]  : "Complete"),
						Widget::TableData($event['received']),
					), 
					'odd'
				);
				
			}

			$cacheTable = Widget::Table(
				Widget::TableHead($cachesTableHead),
				NULL,
				Widget::TableBody($cachesTableBody),
				'selectable'
			);

			$this->Form->appendChild($cacheTable);
			$this->Form->appendChild($Pager->save());
		}

		public function __viewNew(array $fields = array()) {
			if(false === empty($_POST) && false == $fields) {
				$fields = $_POST['fields'];
			}

			$this->setPageType('form');
			$this->setTitle(__(
				'%1$s &ndash; %2$s &ndash; %3$s',
				array(
					__('Symphony'),
					__('XML Importers'),
					__('Run XML Importer')
				)
			));
			$this->appendSubheading(__('New Event'));
			
			//For Debugging.
			$logToFile = true;
			
			//Should you need to check that your messages are coming from the correct topicArn
			$restrictByTopic = false;
			$allowedTopic = "arn:aws:sns:us-east-1:318514470594:WAYJ_NowPlaying_Test";
			
			//For security you can (should) validate the certificate, this does add an additional time demand on the system.
			//NOTE: This also checks the origin of the certificate to ensure messages are signed by the AWS SNS SERVICE.
			//Since the allowed topicArn is part of the validation data, this ensures that your request originated from
			//the service, not somewhere else, and is from the topic you think it is, not something spoofed.
			$verifyCertificate = false;
			$sourceDomain = "sns.us-east-1.amazonaws.com";
			 
			
			//////
			//// OPERATION
			//////
			
			$signatureValid = false;
			$safeToProcess = true; //Are Security Criteria Set Above Met? Changed programmatically to false on any security failure.
			
			if($logToFile){
				////LOG TO FILE:
				$dateString = date("Ymdhis");
				$dateString = MANIFEST . "/logs/sns_".$dateString.".txt";
			
				$myFile = $dateString;
				$fh = fopen($myFile, 'w') or die("Log File Cannot Be Opened.");
			}
			
			
			//Get the raw post data from the request. This is the best-practice method as it does not rely on special php.ini directives
			//like $HTTP_RAW_POST_DATA. Amazon SNS sends a JSON object as part of the raw post body.
			$json = json_decode(file_get_contents("php://input"));
			
			
			//Check for Restrict By Topic
			if($restrictByTopic){
				if($allowedTopic != $json->TopicArn){
					$safeToProcess = false;
					if($logToFile){
						fwrite($fh, "ERROR: Allowed Topic ARN: ".$allowedTopic." DOES NOT MATCH Calling Topic ARN: ". $json->TopicArn . "\n");
					}
				}
			}
			
			
			//Check for Verify Certificate
			if($verifyCertificate){
			
				//Check For Certificate Source
				$domain = getDomainFromUrl($json->SigningCertURL);
				if($domain != $sourceDomain){
					$safeToProcess = false;
					if($logToFile){
						fwrite($fh, "Key domain: " . $domain . " is not equal to allowed source domain:" .$sourceDomain. "\n");
					}
				}
			
				//Build Up The String That Was Originally Encoded With The AWS Key So You Can Validate It Against Its Signature.
				if($json->Type == "SubscriptionConfirmation"){
					$validationString = "";
					$validationString .= "Message\n";
					$validationString .= $json->Message . "\n";
					$validationString .= "MessageId\n";
					$validationString .= $json->MessageId . "\n";
					$validationString .= "SubscribeURL\n";
					$validationString .= $json->SubscribeURL . "\n";
					$validationString .= "Timestamp\n";
					$validationString .= $json->Timestamp . "\n";
					$validationString .= "Token\n";
					$validationString .= $json->Token . "\n";
					$validationString .= "TopicArn\n";
					$validationString .= $json->TopicArn . "\n";
					$validationString .= "Type\n";
					$validationString .= $json->Type . "\n";
				}else{
					$validationString = "";
					$validationString .= "Message\n";
					$validationString .= $json->Message . "\n";
					$validationString .= "MessageId\n";
					$validationString .= $json->MessageId . "\n";
					if($json->Subject != ""){
						$validationString .= "Subject\n";
						$validationString .= $json->Subject . "\n";
					}
					$validationString .= "Timestamp\n";
					$validationString .= $json->Timestamp . "\n";
					$validationString .= "TopicArn\n";
					$validationString .= $json->TopicArn . "\n";
					$validationString .= "Type\n";
					$validationString .= $json->Type . "\n";
				}
				if($logToFile){
					fwrite($fh, "Data Validation String:");
					fwrite($fh, $validationString);
				}
			
				$signatureValid = validateCertificate($json->SigningCertURL, $json->Signature, $validationString);
			
				if(!$signatureValid){
					$safeToProcess = false;
					if($logToFile){
						fwrite($fh, "Data and Signature Do No Match Certificate or Certificate Error.\n");
					}
				}else{
					if($logToFile){
						fwrite($fh, "Data Validated Against Certificate.\n");
					}
				}
			}
			
			if($safeToProcess){
			
				//Handle A Subscription Request Programmatically
				if($json->Type = "SubscriptionConfirmation"){
					//RESPOND TO SUBSCRIPTION NOTIFICATION BY CALLING THE URL
			
					if($logToFile){
						fwrite($fh, $json->SubscribeURL);
					}
			
					$curl_handle=curl_init();
					curl_setopt($curl_handle,CURLOPT_URL,$json->SubscribeURL);
					curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
					curl_exec($curl_handle);
					curl_close($curl_handle);	
					
					Symphony::Database()->insert(array(
						'label'      => "SNS",
						'type' 		 => "Confirmation",
						'topic' 	 => "",
						'message'    => "",
						'received'   => DateTimeObj::get('Y-m-d H:i:s')
					), 'sym_extensions_memcache_events');
					
				}
			
				//Handle a Notification Programmatically
				if($json->Type = "Notification"){
				
					$process = new Process("php ".EXTENSIONS."/shell/bin/symphony memcache prime ". $id);
					
					Symphony::Database()->insert(array(
						'label'      => "SNS",
						'type' 		 => "Notification",
						'topic' 	 => General::sanitize($json->TopicArn),
						'message'    => General::sanitize($json->Message),
						'received'   => DateTimeObj::get('Y-m-d H:i:s'),
						'process_id' => $process->getPid()
					), 'sym_extensions_memcache_events');
					
				}
			}
			
			//Clean Up For Debugging.
			if($logToFile){
				ob_start();
				print_r( $json );
				$output = ob_get_clean();
			
				fwrite($fh, $output);
			
				////WRITE LOG
				fclose($fh);
			}
			
			
			//A Function that takes the key file, signature, and signed data and tells us if it all matches.
			function validateCertificate($keyFileURL, $signatureString, $data){
			
				$signature = base64_decode($signatureString);
				// fetch certificate from file and ready it
				$fp = fopen($keyFileURL, "r");
				$cert = fread($fp, 8192);
				fclose($fp);
			
				$pubkeyid = openssl_get_publickey($cert);
			
				$ok = openssl_verify($data, $signature, $pubkeyid, OPENSSL_ALGO_SHA1);
			
			
				if ($ok == 1) {
				    return true;
				} elseif ($ok == 0) {
				    return false;
			
				} else {
				    return false;
				}	
			}
			
			//A Function that takes a URL String and returns the domain portion only
			function getDomainFromUrl($urlString){
				$domain = "";
				$urlArray = parse_url($urlString);
			
				if($urlArray == false){
					$domain = "ERROR";
				}else{
					$domain = $urlArray['host'];
				}
			
				return $domain;
			}
			
		}

	}