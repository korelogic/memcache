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
	 * We need a few standard Symphony libraries as well:
	 */
	require_once EXTENSIONS.'/memcache/lib/class.process.php';
	require_once EXTENSIONS.'/pager/lib/class.pager.php';
	require_once TOOLKIT.'/class.administrationpage.php';
	require_once TOOLKIT.'/class.sectionmanager.php';

	/**
	 * @package extensions/memcaches
	 */
	/**
	 * This class is responsible for generating the management areas of this extension.
	 */
	class ContentExtensionMemcacheCache extends AdministrationPage {
	
		/**
		 * Represents the total number of records for the particular data set. This is used to 
		 * calculate the number of total pages to navigate through.
		 * @var integer
		 * @access private
		 */
		private 		$sectionNamesArray;

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
					__('Memcache')
				)
			));

			$btns = new XMLElement('div');
			
			$btns->appendChild(Widget::Anchor(
				__('New Cache'), 
				Extension_Memcache::baseUrl().'/cache/new/', 
				__('Create a new cache'), 
				'create button', 
				NULL, 
				array('accesskey' => 'c')
			));
			
			$btns->appendChild(Widget::Anchor(
				__('Servers'), 
				Extension_Memcache::baseUrl().'/servers/', 
				__('Mange memcache server cluster'), 
				'button', 
				NULL
			));
			
			$btns->appendChild(Widget::Anchor(
				__('Events'), 
				Extension_Memcache::baseUrl().'/events/', 
				__('New notification events'), 
				'button', 
				NULL
			));
			
			$this->appendSubheading(__('Memcache'), $btns);

			
			$cachesTableHead = array(
					array(__('Cache'),        'col'),
					array(__('Section'),      'col'),
					array(__('Key'),     	  'col'),
					array(__('Process'),      'col'),
					array(__('Primed'),       'col'),
					array(__('Active'),       'col')
			);

			$totalCaches = array_pop(Symphony::Database()->fetch("SELECT COUNT(1) AS count FROM `sym_extensions_memcache`"));

			$Pager = Pager::factory($totalCaches['count'], Symphony::Configuration()->get('pagination_maximum_rows', 'symphony'), 'pg');

			$memcaches = Symphony::Database()->fetch('
				SELECT
					`id`,
					`label`,
					`section_id`,
					`updated`,
					`key_id`,
					`process_id`,
					`is_active` 
				FROM `sym_extensions_memcache`
				ORDER BY `id` DESC '.$Pager->getLimit(true)
			);

			$cachesTableBody = array();
			if(false == $memcaches) {
				$cachesTableBody[] = Widget::TableRow(array(
						Widget::TableData(__('None found.'), 'inactive', NULL, count($cachesTableHead))
					)
				);
			} else foreach($memcaches as $memcache) {
				$labelRow = Widget::TableData(Widget::Anchor($memcache['label'], Extension_Memcache::baseUrl()."/cache/edit/{$memcache['id']}"));
				$labelRow->appendChild(Widget::Input('items['.$memcache['id'].']', 'on', 'checkbox'));

				$fieldManager = new FieldManager($this->_Parent);

				$process = new Process();
				$process->setPid($memcache['process_id']);
				
				$status = $process->status();

				$cachesTableBody[] = Widget::TableRow(array(
						$labelRow,
						Widget::TableData($this->sectionNamesArray[$memcache['section_id']]),
						Widget::TableData($fieldManager->fetch($memcache['key_id'])->get('label')),
						Widget::TableData((bool) $status ? "Priming ". $status[2]  : "Complete"),
						Widget::TableData(($memcache['updated'] == "") ? "Never" : $memcache['updated']),
						Widget::TableData((bool) $memcache['is_active'] ? 'Yes' : 'No')
					), 
					'odd'
				);
				
				//echo var_dump($process->status());
				//die();
				
			}

			$cacheTable = Widget::Table(
				Widget::TableHead($cachesTableHead),
				NULL,
				Widget::TableBody($cachesTableBody),
				'selectable'
			);

			$this->Form->appendChild($cacheTable);
			
			$options = array(
				array(NULL, false, __('With Selected...')),
				array('prime', false, __('Prime Cache')),
				array('kill', false, __('Kill Task'), 'confirm', null, array(
					'data-message' => __('Are you sure you to kill this task?')
				)),
				array('disable', false, __('Disable')),
				array('enable', false, __('Enable')),
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
				
					case 'kill':
					
						$cache = reset(Symphony::Database()->fetch('
							SELECT
								`id`,
								`label`,
								`process_id`,
								`is_active` 
							FROM `sym_extensions_memcache`
							WHERE `id` = '.(int) $id
						));
						
						$process = new Process();
						$process->setPid($cache['process_id']);
												
						if((bool) $process->status()){
						
							$process->stop();
							
							sleep(5);
						
							$this->pageAlert(
								__('Task killed.'),
								Alert::SUCCESS
							);
							return;
						}else{
							$this->pageAlert(
								__('Task is not running.'),
								Alert::ERROR
							);
							return;
						}
					
						break;
					
					case 'prime':
					
						$cache = reset(Symphony::Database()->fetch('
							SELECT
								`id`,
								`label`,
								`process_id`,
								`is_active` 
							FROM `sym_extensions_memcache`
							WHERE `id` = '.(int) $id
						));
						
						if( (bool) $cache['is_active']){
						
							$processCheck = new Process();
							$processCheck->setPid($cache['process_id']);
													
							if((bool) $processCheck->status()){
								$this->pageAlert(
									__('Cache prime is already running, try again once complete.'),
									Alert::ERROR
								);
								return;
							}else{
								$process = new Process("php ".EXTENSIONS."/shell/bin/symphony memcache prime ". $id);
								Symphony::Database()->update(array('process_id' => $process->getPid()), 'sym_extensions_memcache', '`id` = '.(int) $id);
								
								$this->pageAlert(
									__('Cache priming, this may take a few minutes.'),
									Alert::SUCCESS
								);
								return;
							}
							
						}else{
							$this->pageAlert(
								__('This cache is not active.'),
								Alert::ERROR
							);
							return;
						}

						break;
				
					case 'enable':
						Symphony::ExtensionManager()->notifyMembers('MemCachePreEnable', '/extension/memcache/', array('id' => (int) $id));
						Symphony::Database()->update(array('is_active' => true), 'sym_extensions_memcache', '`id` = '.(int) $id);
						
						break;
					case 'disable':
						Symphony::ExtensionManager()->notifyMembers('MemCachePreDisable', '/extension/memcache/', array('id' => (int) $id));
						Symphony::Database()->update(array('is_active' => false), 'sym_extensions_memcache', '`id` = '.(int) $id);
						
						break;
					case 'delete':
						Symphony::ExtensionManager()->notifyMembers('MemCachePreDelete', '/extension/memcache/', array('id' => (int) $id));
						Symphony::Database()->delete('sym_extensions_memcache', '`id` = '.(int) $id);
						
						break;
				}
			}

			redirect(Extension_Memcache::baseUrl()."/cache");
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
					FROM `sym_extensions_memcache`
					WHERE
						`section_id` = ".(int) $fields['section_id']."
					AND `key_id`       = '".trim($fields['section_id'])."'
				"));

				if($uniqueConstraintCheck['count']) {
					$this->_errors = array(
						'section_id' => __('Unique constraint violation.'),
						'key_id' => __('Unique constraint violation.')
					);

					$this->pageAlert(
						__('The cache could not be saved. There has been a unique constraint violation. Please ensure you have a unique combination of `Source` and `Key`.'),
						Alert::ERROR
					);
					return;
				}
			}

			if($this->_errors) {
				$this->pageAlert(
					__('The cache could not be saved. Please ensure you filled out the form properly.'),
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
						'section_id' => (int) $fields['section_id'],
						'key_id'     => (int)  $fields['key_id_'.$fields['section_id']],
						'is_active'  => isset($fields['is_active']) ? TRUE : FALSE
					), 'sym_extensions_memcache', '`id` = '.(int) $fields['id']);
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
						'section_id' => (int) $fields['section_id'],
						'key_id'     => (int) $fields['key_id_'.$fields['section_id']],
						'is_active'  => isset($fields['is_active']) ? TRUE : FALSE
					), 'sym_extensions_memcache');

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
					__('MemCache has been updated successfully!'),
					Alert::SUCCESS
				);
				return;
			}

			redirect(Extension_Memcache::baseUrl().'/cache');
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
			$this->setTitle(__('%1$s &ndash; %2$s', array(__('Symphony'), __('MemCaches'))));
			$this->appendSubheading('<a href="'.Extension_Memcache::baseUrl()."/cache".'">Memcache</a> &rarr; ' . (false === isset($fields['id']) ? __('Untitled') : $fields['label']));

			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Cache Settings')));

			$label = Widget::Label(__('Key Prefix'));
			$label->appendChild(Widget::Input(
				'fields[label]', General::sanitize($fields['label'])
			));

			if(isset($this->_errors['label']))
				$label = $this->wrapFormElementWithError($label, $this->_errors['label']);

			//$callback = Widget::Label(__('Callback URL'));
			//$callback->appendChild(Widget::Input(
			//	'fields[callback]', General::sanitize($fields['callback'])
			//));

			//if(isset($this->_errors['callback']))
			//	$callback = $this->wrapFormElementWithError($callback, $this->_errors['callback']);

			

			$sectioncache = Widget::Label(__('Source'));
			$options = array();

			
			array_unshift($options, array('label' => __('Sections'), 'options' => array()));
			foreach($this->sectionNamesArray as $id => $name) {
			
				$options[0]['options'][] = array($id, ($fields['source'] == $id), General::sanitize($name));
			}
			
			//Select if editing
			if(isset($fields['section_id'])) {
				foreach($options[0]['options'] as &$option) {
					if($option[0] == $fields['section_id'])
						$option[1] = true;
				}
			}
		
			$sectioncache->appendChild(Widget::Select('fields[section_id]', $options, array('id' => 'context')));


			if(isset($this->_errors['section_id']))
				$sectioncache = $this->wrapFormElementWithError($section, $this->_errors['section_id']);

			//Key
			if(isset($this->_errors['key_id']))
				$key = $this->wrapFormElementWithError($key, $this->_errors['key_id']);

			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			
			$group->appendChild($sectioncache);
			
			$sectionManager = new SectionManager($this->_Parent);
			$sections = $sectionManager->fetch(NULL, 'ASC', 'name');

			if (!is_array($sections)) $sections = array();
			$field_groups = array();

			foreach($sections as $section){
				$field_groups[$section->get('id')] = array('fields' => $section->fetchFields(), 'section' => $section);
			}
			
			foreach($field_groups as $section_id => $section_data){

				if(is_array($section_data['fields']) && !empty($section_data['fields'])){
					$options = array();
					
					foreach($section_data['fields'] as $input){
						array_push($options, array($input->get('id'), false, $input->get('label')));
					}
					
					//Select if editing
					if(isset($fields['key_id'])) {
						foreach($options as &$option) {
							if($option[0] == $fields['key_id']){
								$option[1] = true;
							}
						}
					}
					
					$div = new XMLElement('div');
					$div->setAttribute('class', 'contextual ' . $section_data['section']->get('id') . ' irrelevant');
		
					$key = Widget::Label(__('Key'));
					$key->appendChild(Widget::Select('fields[key_id_'.$section_data['section']->get('id').']', $options));
					
					$div->appendChild($key);
				}
				
				$group->appendChild($div);

			}


			$isActive = Widget::Label();
			$isActiveCheckbox = Widget::Input('fields[is_active]', 'yes', 'checkbox', ($fields['is_active'] ? array('checked' => 'checked') : NULL));

			$isActive->setValue(__('%1$s Activate Cache', array($isActiveCheckbox->generate())));

			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			$actions->appendChild(Widget::Input(
				'action[save]', isset($fields['id']) ? __('Update Cache') : __('Create Cache'),
				'submit', array('accesskey' => 's')
			));

			if(isset($fields['id'])){
				$button = new XMLElement('button', __('Delete'));
				$button->setAttributeArray(array('name' => 'action[delete]', 'class' => 'button confirm delete', 'title' => __('Delete this memcache'), 'accesskey' => 'd', 'data-message' => __('Are you sure you want to delete this memcache?')));
				$actions->appendChild($button);
			}

			$fieldset->appendChild($label);
			$fieldset->appendChild($group);
			$fieldset->appendChild($isActive);
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
				$memcache = Symphony::Database()->fetch('
					SELECT
						`id`,
						`label`,
						`section_id`,
						`key_id`,
						`is_active` 
					FROM `sym_extensions_memcache`
					WHERE `id` = '.(int) $this->_context[1]
				);

				if(false == $memcache) {
					$this->pageAlert(
						__('The cache you specified could not be located.'),
						Alert::ERROR
					);
					return $this->__viewIndex();
				}

				if(isset($this->_context[2]) && $this->_context[2] == 'created') {
					$this->pageAlert(
						__(
							'MemCache created at %1$s. <a href="%2$s" accesskey="c">Create another?</a> <a href="%3$s" accesskey="a">View all Caches</a>',
							array(
								DateTimeObj::getTimeAgo(__SYM_TIME_FORMAT__),
								SYMPHONY_URL . '/extension/memcache/cache/new/',
								SYMPHONY_URL . '/extension/memcache/cache/'
							)
						),
						Alert::SUCCESS
					);
				}

				return $this->__viewNew($memcache[0]);
			}
		}
	}