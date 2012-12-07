<?php
	/**
	 * Requires the `pager` extension to be installed and active.
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
	require_once EXTENSIONS.'/pager/lib/class.pager.php';
	require_once TOOLKIT.'/class.administrationpage.php';
	require_once TOOLKIT.'/class.sectionmanager.php';


	/**
	 * @package extensions/memcaches
	 */
	/**
	 * This class is responsible for generating the management areas of this extension.
	 */
	class ContentExtensionMemcacheServers extends AdministrationPage {
		/**
		 * Represents the total number of records for the particular data set. This is used to 
		 * calculate the number of total pages to navigate through.
		 * @var integer
		 * @access private
		 */
		private $sectionNamesArray;

		/**
		 * Instantiates the extension and populates array ContentExtensionMemcacheHooks::$sectionNamesArray
		 * with a key/value set representing a list of sections.
		 *
		 * @param Administration $parent
		 *  Instance of class AdministrationPage
		 * @access public
		 * @return NULL
		 */
		public function __construct(Administration &$parent) {
			parent::__construct($parent);

			$SectionManager = new SectionManager($this->_Parent);

			$this->sectionNamesArray = array();
			foreach($SectionManager->fetch(NULL, 'ASC', 'sortorder') as $Section) {
				$this->sectionNamesArray[$Section->get('id')] = $Section->get('name');
			}
		}

		/**
		 * Displays the memcaches index page within the Symphony administration panel.
		 *
		 * @access public
		 * @param none
		 * @return NULL
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
					__('Memcache Servers')
				)
			));
			
			$btns = new XMLElement('div');

			$btns->appendChild(Widget::Anchor(
				__('New Server'), 
				Extension_Memcache::baseUrl().'/servers/new/', 
				__('Create a new memcache server'), 
				'create button', 
				NULL, 
				array('accesskey' => 'c')
			));
			
			$this->appendSubheading('<a href="'.Extension_Memcache::baseUrl()."/cache".'">Memcache</a> &rarr; Servers', $btns);

			$serversTableHead = array(
					array(__('Server'),       'col'),
					array(__('Status'),       'col'),
					array(__('Version'),      'col'),
					array(__('Connections'),  'col'),
					array(__('Total Keys'),   'col'),
					array(__('Hits'),   	  'col'),
					array(__('Misses'),   	  'col'),
					array(__('Evictions'),    'col'),
					array(__('Read'),    	  'col'),
					array(__('Written'),      'col'),
					array(__('Max Storage'),      'col'),
					array(__('Uptime'),       'col')
			);

			$totalCaches = array_pop(Symphony::Database()->fetch("SELECT COUNT(1) AS count FROM `sym_extensions_memcache_servers`"));

			$Pager = Pager::factory($totalCaches['count'], Symphony::Configuration()->get('pagination_maximum_rows', 'symphony'), 'pg');

			$servers = Symphony::Database()->fetch('
				SELECT
					`id`,
					`label`,
					`server`,
					`port`
				FROM `sym_extensions_memcache_servers`
				ORDER BY `label` ASC '.$Pager->getLimit(true)
			);

			$serversTableBody = array();
			if(false == $servers) {
				$serversTableBody[] = Widget::TableRow(array(
						Widget::TableData(__('None found.'), 'inactive', NULL, count($serversTableHead))
					)
				);
			} else foreach($servers as $server) {
				$labelRow = Widget::TableData(Widget::Anchor($server['label'], Extension_Memcache::baseUrl()."/servers/edit/{$server['id']}"));
				$labelRow->appendChild(Widget::Input('items['.$server['id'].']', 'on', 'checkbox'));
				
				$memcache = new Memcache;
				$memcacheUp = "DOWN";
				try{
					$memcacheUp = $memcache->connect($server['server'], $server['port']);
					$memcacheUp = "OK";	
				}catch(Exception $e){
					$memcacheUp = $e->getMessage();
				}
				
				if($memcache->getServerStatus($server['server'], $server['port']) == 1){
				
					$memcacheStats = $memcache->getStats();
					
					if($memcacheStats["get_hits"] == 0){
						$percCacheHit="";
						$percCacheMiss="";
					}else{
						$percCacheHit=((real)$memcacheStats["get_hits"] / (real)$memcacheStats ["cmd_get"] *100); 
						$percCacheMiss = 100 - $percCacheHit;
						$percCacheHit="(".round($percCacheHit,3)."%)";
						$percCacheMiss="(".round($percCacheMiss,3). "%)"; 
					}
	
					$serversTableBody[] = Widget::TableRow(
						array(
							$labelRow,
							Widget::TableData($memcacheUp),
							Widget::TableData($memcacheStats["version"]),
							Widget::TableData($memcacheStats["curr_connections"]),
							Widget::TableData($memcacheStats["curr_items"]),
							Widget::TableData($memcacheStats["get_hits"]. " " . $percCacheHit),
							Widget::TableData($memcacheStats["get_misses"]. " " . $percCacheMiss),
							Widget::TableData($memcacheStats["evictions"]),
							Widget::TableData(number_format((real)$memcacheStats["bytes_read"]/(1024*1024) , 2)."MB"),
							Widget::TableData(number_format((real)$memcacheStats["bytes_written"]/(1024*1024), 2)."MB"),
							Widget::TableData(number_format((real)$memcacheStats["limit_maxbytes"]/(1024*1024), 2)."MB"),
							Widget::TableData(gmdate("H:i:s", $memcacheStats["uptime"]))
						), 
						'odd'
					);
				}else{
					$serversTableBody[] = Widget::TableRow(
						array(
							$labelRow,
							Widget::TableData("Disconnected"),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData(""),
							Widget::TableData("")
						), 
						'odd'
					);
				}
			}

			$cacheTable = Widget::Table(
				Widget::TableHead($serversTableHead),
				NULL,
				Widget::TableBody($serversTableBody),
				'selectable'
			);

			$this->Form->appendChild($cacheTable);
			
			$options = array(
				array(NULL, false, __('With Selected...')),
				array('flush', false, __('Flush'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to flush the selected cache(s)')
				)),
				array('delete', false, __('Delete'), 'confirm', null, array(
					'data-message' => __('Are you sure you want to delete the selected cache(s)')
				))
			);

			$tableActions = new XMLElement('div');
			$tableActions->setAttribute('class', 'actions');

			$tableActions->appendChild(Widget::Select('with-selected', $options));
			$tableActions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));

			$this->Form->appendChild($tableActions);
			$this->Form->appendChild($Pager->save());
		}

		/**
		 * Displays the memcaches index page within the Symphony administration panel.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __actionIndex() {
			if(false === isset($_POST['with-selected'])) {
				return;
			}

			foreach($_POST['items'] as $id => $state) {
				switch($_POST['with-selected']) {
					case 'flush':
					
						$server = reset(Symphony::Database()->fetch("
							SELECT
								`id`,
								`label`,
								`server`,
								`port`
							FROM `sym_extensions_memcache_servers`
							WHERE id = ".(int) $id
						));
						

						$memcache = new Memcache;
						$memcache->connect($server["server"], $server["port"]);
						$memcache->flush();
						
						break;
				
					case 'enable':
						/**
						 * Fires off before a memcache is enabled.
						 *
						 * @delegate memcachePreEnable
						 * @param string $context
						 * '/extensions/memcaches/'
						 * @param integer id
						 *  memcache record id
						 */
						Symphony::ExtensionManager()->notifyMembers('MemCachePreEnable', '/extension/memcache/', array('id' => (int) $id));

						Symphony::Database()->update(array('is_active' => true), 'sym_extensions_memcache_servers', '`id` = '.(int) $id);
						break;
					case 'disable':
						/**
						 * Fires off before a memcache is disabled.
						 *
						 * @delegate memcachePreDisable
						 * @param string $context
						 * '/extensions/memcaches/'
						 * @param integer id
						 *  memcache record id
						 */
						Symphony::ExtensionManager()->notifyMembers('MemCachePreDisable', '/extension/memcache/', array('id' => (int) $id));

						Symphony::Database()->update(array('is_active' => false), 'sym_extensions_memcache_servers', '`id` = '.(int) $id);
						break;
					case 'delete':
						/**
						 * Fires off before a memcache is deleted.
						 *
						 * @delegate memcachePreDelete
						 * @param string $context
						 * '/extensions/memcaches/'
						 * @param integer id
						 *  memcache record id
						 */
						Symphony::ExtensionManager()->notifyMembers('MemCachePreDelete', '/extension/memcache/', array('id' => (int) $id));

						Symphony::Database()->delete('sym_extensions_memcache_servers', '`id` = '.(int) $id);
						break;
				}
			}

			redirect(Extension_Memcache::baseUrl()."/servers");
		}

		/**
		 * Processes and validates new and updated memcache records.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __actionNew() {
			require_once TOOLKIT.'/util.validators.php';
			$fields = $_POST['fields'];
			$this->_errors = array();

			if(false === isset($fields['label']) || trim($fields['label']) == '')
				$this->_errors['label'] = __('`Label` is a required field.');

			if(empty($this->_errors) && false === isset($fields['id'])) {
				$uniqueConstraintCheck = array_pop(Symphony::Database()->fetch("
					SELECT COUNT(1) AS count 
					FROM `sym_extensions_memcache_servers`
					WHERE 
						`label`   = '".trim($fields['label'])."'
					AND `server`  = '".trim($fields['server'])."'
					AND `port`    = '".trim($fields['port'])."'
				"));

				if($uniqueConstraintCheck['count']) {
					$this->_errors = array(
						'label' => __('Unique constraint violation.'),
					);

					$this->pageAlert(
						__('The server could not be saved. There has been a unique constraint violation. Please ensure you have a unique combination of `Name`, `Url` and `Port`.'),
						Alert::ERROR
					);
					return;
				}
			}

			if($this->_errors) {
				$this->pageAlert(
					__('The server could not be saved. Please ensure you filled out the form properly.'),
					Alert::ERROR
				);
				return;
			}

			try {
				if(isset($fields['id'])) {
					/**
					 * Fires off before a memcache is updated.
					 *
					 * @delegate memcachePreUpdate
					 * @param string $context
					 * '/extensions/memcaches/'
					 * @param array $fields
					 *  Values representing a memcache
					 */
					Symphony::ExtensionManager()->notifyMembers('MemCachePreUpdate', '/extension/memcache/', array('fields' => &$fields));

					Symphony::Database()->update(array(
						'label'      => General::sanitize($fields['label']),
						'server' => General::sanitize($fields['server']),
						'port'     => (int) $fields['port']
					), 'sym_extensions_memcache_servers', '`id` = '.(int) $fields['id']);
				} else {
					/**
					 * Fires off before a memcache is created.
					 *
					 * @delegate memcachePreInsert
					 * @param string $context
					 * '/extensions/memcaches/'
					 * @param array $fields
					 *  Values representing a memcache
					 */
					Symphony::ExtensionManager()->notifyMembers('MemCachePreInsert', '/extension/memcache/', array('fields' => &$fields));

					Symphony::Database()->insert(array(
						'label'      => General::sanitize($fields['label']),
						'server' => General::sanitize($fields['server']),
						'port'     => (int) $fields['port']
					), 'sym_extensions_memcache_servers');

					/**
					 * Fires off after a memcache is created.
					 *
					 * @delegate memcachePostInsert
					 * @param string $context
					 * '/extensions/memcaches/'
					 * @param integer $id
					 *  memcache record id
					 */
					Symphony::ExtensionManager()->notifyMembers('MemCachePostInsert', '/extension/memcache/', array('id' => (int) Symphony::Database()->getInsertID()));
				}
			} catch(Exception $Exception) {
				$this->pageAlert(
					$Exception->getMessage(),
					Alert::ERROR
				);
				return;
			}

			if(isset($fields['id'])) {
				$this->pageAlert(
					__('Server has been updated successfully! <a href="'.SYMPHONY_URL . '/extension/memcache/servers/'.'" accesskey="a">View all Servers</a>'),
					Alert::SUCCESS
				);
				return;
			}

			redirect(Extension_Memcache::baseUrl().'/servers');
		}

		/**
		 * Generates the form used to create new, and edit existing, memcaches. Nothing really special
		 * going on here except that when field values are either passed as a parameter or as a $_POST
		 * value, this method will automatically populate the forms with the relevant information.
		 *
		 * @access public
		 * @param none
		 * @return NULL
		 */
		public function __viewNew(array $fields = array()) {
			if(false === empty($_POST) && false == $fields) {
				$fields = $_POST['fields'];
			}

			$this->setPageType('form');
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Symphony'), __('Create Memcache Server'))));
			$this->appendSubheading('<a href="'.Extension_Memcache::baseUrl()."/cache".'">Memcache</a> &rarr; <a href="'.Extension_Memcache::baseUrl()."/servers".'">Servers</a> &rarr; ' . (false === isset($fields['id']) ? __('Untitled') : $fields['label']));

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Server Settings')));

			//Label
			$label = Widget::Label(__('Name'));
			$label->appendChild(Widget::Input(
				'fields[label]', General::sanitize($fields['label'])
			));

			if(isset($this->_errors['label']))
				$label = $this->wrapFormElementWithError($label, $this->_errors['label']);

			
			//Server
			$server = Widget::Label(__('URL'));
			$server->appendChild(Widget::Input(
				'fields[server]', General::sanitize($fields['server'])
			));

			if(isset($this->_errors['server']))
				$server = $this->wrapFormElementWithError($server, $this->_errors['server']);

			//Port
			$port = Widget::Label(__('Port'));
			$port->appendChild(Widget::Input(
				'fields[port]', General::sanitize($fields['port'])
			));

			if(isset($this->_errors['port']))
				$port = $this->wrapFormElementWithError($port, $this->_errors['port']);

			//Actions
			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			$actions->appendChild(Widget::Input(
				'action[save]', isset($fields['id']) ? __('Update Server') : __('Create Server'),
				'submit', array('accesskey' => 's')
			));

			if(isset($fields['id'])){
				$button = new XMLElement('button', __('Delete'));
				$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this memcache'), 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this server?')));
				$actions->appendChild($button);
			}

			$fieldset->appendChild($label);
			$fieldset->appendChild($server);
			$fieldset->appendChild($port);

			$fieldset->appendChild($actions);

			if(isset($fields['id'])) {
				$fieldset->appendChild(Widget::Input('fields[id]', $fields['id'], 'hidden'));
			}

			$this->Form->appendChild($fieldset);
		}

		/**
		 * Called when the 'save changes' button is clicked on the memcache edit screen. Or, alternatively,
		 * when the 'delete' button is clicked from the edit screen. In the case of the latter, we populate
		 * the $_POST array and redirect to '__actionIndex' so we can use the code that's already there to
		 * delete the chosen memcache. However, saving changes to an existing memcache just redirects to the 
		 * '__actionIndex' method as it houses code for both editing and saving.
		 *
		 * @access public
		 * @param none
		 * @return ContentExtensionMemcacheHooks::__actionIndex() OR ContentExtensionMemcacheHooks::__actionNew()
		 */
		public function __actionEdit() {
			if(isset($_POST['action']['delete']) && isset($_POST['fields']['id'])) {
				$_POST['with-selected'] = 'delete';
				$_POST['items'] = array($_POST['fields']['id'] => '');
				return $this->__actionIndex();
			}

			return $this->__actionNew();
		}

		/**
		 * If a record id of an existing memcache has been provided, this method will attempt
		 * to retrieve the corresponding record from the database and provide the result to
		 * ContentExtensionMemcacheHooks::__viewNew() to generate the resulting form for editing
		 * purposes.
		 *
		 * @access public
		 * @param none
		 * @return ContentExtensionMemcacheHooks::__viewNew();
		 */
		public function __viewEdit() {
			if(
				isset($this->_context[0]) && $this->_context[0] === 'edit' && 
				isset($this->_context[1]) && is_numeric($this->_context[1])
			) {
				$server = Symphony::Database()->fetch('
					SELECT
						`id`,
						`label`,
						`server`,
						`port`
					FROM `sym_extensions_memcache_servers`
					WHERE `id` = '.(int) $this->_context[1]
				);

				if(false == $server) {
					$this->pageAlert(
						__('The server you specified could not be located.'),
						Alert::ERROR
					);
					return $this->__viewIndex();
				}

				if(isset($this->_context[2]) && $this->_context[2] == 'created') {
					$this->pageAlert(
						__(
							'Server created at %1$s. <a href="%2$s" accesskey="c">Create another?</a> <a href="%3$s" accesskey="a">View all Caches</a>',
							array(
								DateTimeObj::getTimeAgo(__SYM_TIME_FORMAT__),
								SYMPHONY_URL . '/extension/memcache/servers/new/',
								SYMPHONY_URL . '/extension/memcache/servers/'
							)
						),
						Alert::SUCCESS
					);
				}

				return $this->__viewNew($server[0]);
			}
		}
	}