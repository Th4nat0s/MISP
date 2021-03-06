<?php

App::uses('AppController', 'Controller');

class TagsController extends AppController {
	public $components = array('Security' ,'RequestHandler');

	public $paginate = array(
			'limit' => 50,
			'order' => array(
					'Tag.name' => 'asc'
			),
			'contain' => array(
				'EventTag' => array(
					'fields' => array('EventTag.event_id')
				),
				'AttributeTag' => array(
					'fields' => array('AttributeTag.event_id', 'AttributeTag.attribute_id')
				),
				'FavouriteTag',
				'Organisation' => array(
					'fields' => array('id', 'name')
				)
			)
	);

	public $helpers = array('TextColour');

	public function index($favouritesOnly = false) {
		$this->loadModel('Attribute');
		$this->loadModel('Event');
		$this->loadModel('Taxonomy');
		$taxonomies = $this->Taxonomy->listTaxonomies(array('full' => false, 'enabled' => true));
		$taxonomyNamespaces = array();
		if (!empty($taxonomies)) foreach ($taxonomies as $taxonomy) $taxonomyNamespaces[$taxonomy['namespace']] = $taxonomy;
		$taxonomyTags = array();
		$this->Event->recursive = -1;
		if ($favouritesOnly) {
			$tag_id_list = $this->Tag->FavouriteTag->find('list', array(
					'conditions' => array('FavouriteTag.user_id' => $this->Auth->user('id')),
					'fields' => array('FavouriteTag.tag_id')
			));
			if (empty($tag_id_list)) $tag_id_list = array(-1);
			$this->paginate['conditions']['AND']['Tag.id'] = $tag_id_list;
		}
		if ($this->_isRest()) {
			unset($this->paginate['limit']);
			$paginated = $this->Tag->find('all', $this->paginate);
		} else {
			$paginated = $this->paginate();
		}
		$sightedEventsToFetch = array();
		$sightedAttributesToFetch = array();
		$csv = array();
		foreach ($paginated as $k => $tag) {
			if (empty($tag['EventTag'])) {
				$paginated[$k]['Tag']['count'] = 0;
			} else {
				$eventIDs = array();
				foreach ($tag['EventTag'] as $eventTag) {
					$eventIDs[] = $eventTag['event_id'];
				}
				$conditions = array('Event.id' => $eventIDs);
				if (!$this->_isSiteAdmin()) $conditions = array_merge(
					$conditions,
					array('OR' => array(
						array('AND' => array(
							array('Event.distribution >' => 0),
							array('Event.published =' => 1)
						)),
						array('Event.orgc_id' => $this->Auth->user('org_id'))
					)));
				$events = $this->Event->find('all', array(
					'fields' => array('Event.id', 'Event.distribution', 'Event.orgc_id'),
					'conditions' => $conditions
				));
				$paginated[$k]['Tag']['count'] = count($events);
			}
			$paginated[$k]['event_ids'] = array();
			$paginated[$k]['attribute_ids'] = array();
			foreach($paginated[$k]['EventTag'] as $et) {
				if (!in_array($et['event_id'], $sightedEventsToFetch)) {
					$sightedEventsToFetch[] = $et['event_id'];
				}
				$paginated[$k]['event_ids'][] = $et['event_id'];
			}
			unset($paginated[$k]['EventTag']);
			foreach($paginated[$k]['AttributeTag'] as $at) {
				if (!in_array($at['attribute_id'], $sightedAttributesToFetch)) {
					$sightedAttributesToFetch[] = $at['attribute_id'];
				}
				$paginated[$k]['attribute_ids'][] = $at['attribute_id'];
			}

			if (empty($tag['AttributeTag'])) {
				$paginated[$k]['Tag']['attribute_count'] = 0;
			} else {
				$attributeIDs = array();
				foreach ($tag['AttributeTag'] as $attributeTag) {
					$attributeIDs[] = $attributeTag['attribute_id'];
				}
				$conditions = array('Attribute.id' => $attributeIDs);
				if (!$this->_isSiteAdmin()) {
					$conditions = array_merge(
						$conditions,
						array('OR' => array(
							array('AND' => array(
								array('Attribute.deleted =' => 0),
								array('Attribute.distribution >' => 0),
								array('Event.distribution >' => 0),
								array('Event.published =' => 1)
							)),
							array('Event.orgc_id' => $this->Auth->user('org_id'))
						)));
				}
				$attributes = $this->Attribute->find('all', array(
					'fields'     => array('Attribute.id', 'Attribute.deleted', 'Attribute.distribution', 'Event.id', 'Event.distribution', 'Event.orgc_id'),
					'contain'    => array('Event' => array('fields' => array('id', 'distribution', 'orgc_id'))),
					'conditions' => $conditions
				));
				$paginated[$k]['Tag']['attribute_count'] = count($attributes);
			}
			unset($paginated[$k]['AttributeTag']);
			if (!empty($tag['FavouriteTag'])) {
				foreach ($tag['FavouriteTag'] as $ft) if ($ft['user_id'] == $this->Auth->user('id')) $paginated[$k]['Tag']['favourite'] = true;
				if (!isset($tag['Tag']['favourite'])) $paginated[$k]['Tag']['favourite'] = false;
			} else $paginated[$k]['Tag']['favourite'] = false;
			unset($paginated[$k]['FavouriteTag']);
			if (!empty($taxonomyNamespaces)) {
				$taxonomyNamespaceArrayKeys = array_keys($taxonomyNamespaces);
				foreach ($taxonomyNamespaceArrayKeys as $tns) {
					if (substr(strtoupper($tag['Tag']['name']), 0, strlen($tns)) === strtoupper($tns)) {
						$paginated[$k]['Tag']['Taxonomy'] = $taxonomyNamespaces[$tns];
						if (!isset($taxonomyTags[$tns])) $taxonomyTags[$tns] = $this->Taxonomy->getTaxonomyTags($taxonomyNamespaces[$tns]['id'], true);
						$paginated[$k]['Tag']['Taxonomy']['expanded'] = isset($taxonomyTags[$tns][strtoupper($tag['Tag']['name'])]) ? $taxonomyTags[$tns][strtoupper($tag['Tag']['name'])] : $tag['Tag']['name'];
					}
				}
			}
		}
		$this->loadModel('Sighting');
		$sightings['event'] = $this->Sighting->getSightingsForObjectIds($this->Auth->user(), $sightedEventsToFetch);
		$sightings['attribute'] = $this->Sighting->getSightingsForObjectIds($this->Auth->user(), $sightedAttributesToFetch, 'attribute');
		foreach ($paginated as $k => $tag) {
			$objects = array('event', 'attribute');
			foreach ($objects as $object) {
				foreach ($tag[$object . '_ids'] as $objectid) {
					if (isset($sightings[$object][$objectid])) {
						foreach ($sightings[$object][$objectid] as $date => $sightingCount) {
							if (!isset($tag['sightings'][$date])) {
								$tag['sightings'][$date] = $sightingCount;
							} else {
								$tag['sightings'][$date] += $sightingCount;
							}
						}
					}
				}
			}
			$startDate = !empty($tag['sightings']) ? min(array_keys($tag['sightings'])) : date('Y-m-d');
			$startDate = date('Y-m-d', strtotime("-3 days", strtotime($startDate)));
			$to = date('Y-m-d', time());
			for ($date = $startDate; strtotime($date) <= strtotime($to); $date = date('Y-m-d',strtotime("+1 day", strtotime($date)))) {
				if (!isset($csv[$k])) {
					$csv[$k] = 'Date,Close\n';
				}
				if (isset($tag['sightings'][$date])) {
					$csv[$k] .= $date . ',' . $tag['sightings'][$date] . '\n';
				} else {
					$csv[$k] .= $date . ',0\n';
				}
			}
			unset($paginated[$k]['event_ids']);
		}
		if ($this->_isRest()) {
			foreach ($paginated as $key => $tag) {
				$paginated[$key] = $tag['Tag'];
			}
			$this->set('Tag', $paginated);
			$this->set('_serialize', array('Tag'));
		} else {
			$this->set('csv', $csv);
			$this->set('list', $paginated);
			$this->set('favouritesOnly', $favouritesOnly);
		}
		// send perm_tagger to view for action buttons
	}

	public function add() {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) throw new NotFoundException('You don\'t have permission to do that.');
		if ($this->request->is('post')) {
			if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];
			if (!isset($this->request->data['Tag']['colour'])) $this->request->data['Tag']['colour'] = $this->Tag->random_color();
			if (isset($this->request->data['Tag']['id'])) unset($this->request->data['Tag']['id']);
			if ($this->Tag->save($this->request->data)) {
				if ($this->_isRest()) $this->redirect(array('action' => 'view', $this->Tag->id));
				$this->Session->setFlash('The tag has been saved.');
				$this->redirect(array('action' => 'index'));
			} else {
				if ($this->_isRest()) {
					$error_message = '';
					foreach ($this->Tag->validationErrors as $k => $v) $error_message .= '[' . $k . ']: ' . $v[0];
					throw new MethodNotAllowedException('Could not add the Tag. ' . $error_message);
				} else {
					$this->Session->setFlash('The tag could not be saved. Please, try again.');
				}
			}
		}
		$this->loadModel('Organisation');
		$temp = $this->Organisation->find('all', array(
			'conditions' => array('local' => 1),
			'fields' => array('id', 'name'),
			'recursive' => -1
		));
		$orgs = array(0 => 'Unrestricted');
		if (!empty($temp)) {
			foreach ($temp as $org) {
				$orgs[$org['Organisation']['id']] = $org['Organisation']['name'];
			}
		}
		$this->set('orgs', $orgs);
	}

	public function quickAdd() {
		if ((!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) || !$this->request->is('post')) throw new NotFoundException('You don\'t have permission to do that.');
		if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];
		if ($this->Tag->quickAdd($this->request->data['Tag']['name'])) {
			$this->Session->setFlash('The tag has been saved.');
		} else {
			$this->Session->setFlash('The tag could not be saved. Please, try again.');
		}
		$this->redirect($this->referer());
	}

	public function edit($id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) {
			throw new NotFoundException('You don\'t have permission to do that.');
		}
		$this->Tag->id = $id;
		if (!$this->Tag->exists()) {
			throw new NotFoundException('Invalid tag');
		}
		if ($this->request->is('post') || $this->request->is('put')) {
			$this->request->data['Tag']['id'] = $id;
			if (isset($this->request->data['Tag']['request'])) $this->request->data['Tag'] = $this->request->data['Tag']['request'];

			if ($this->Tag->save($this->request->data)) {
				if ($this->_isRest()) $this->redirect(array('action' => 'view', $id));
				$this->Session->setFlash('The Tag has been edited');
				$this->redirect(array('action' => 'index'));
			} else {
				if ($this->_isRest()) {
					$error_message = '';
					foreach ($this->Tag->validationErrors as $k => $v) $error_message .= '[' . $k . ']: ' . $v[0];
					throw new MethodNotAllowedException('Could not add the Tag. ' . $error_message);
				}
				$this->Session->setFlash('The Tag could not be saved. Please, try again.');
			}
		}
		$this->loadModel('Organisation');
		$temp = $this->Organisation->find('all', array(
			'conditions' => array('local' => 1),
			'fields' => array('id', 'name'),
			'recursive' => -1
		));
		$orgs = array(0 => 'Unrestricted');
		if (!empty($temp)) {
			foreach ($temp as $org) {
				$orgs[$org['Organisation']['id']] = $org['Organisation']['name'];
			}
		}
		$this->set('orgs', $orgs);
		$this->request->data = $this->Tag->read(null, $id);
	}

	public function delete($id) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tag_editor']) {
			throw new NotFoundException('You don\'t have permission to do that.');
		}
		if (!$this->request->is('post')) {
			throw new MethodNotAllowedException();
		}
		$this->Tag->id = $id;
		if (!$this->Tag->exists()) {
			throw new NotFoundException('Invalid tag');
		}
		if ($this->Tag->delete()) {
			if ($this->_isRest()) {
				$this->set('name', 'Tag deleted.');
				$this->set('message', 'Tag deleted.');
				$this->set('url', '/tags/delete/' . $id);
				$this->set('_serialize', array('name', 'message', 'url'));
			}
			$this->Session->setFlash(__('Tag deleted'));
		} else {
			if ($this->_isRest()) throw new MethodNotAllowedException('Could not delete the tag, or tag doesn\'t exist.');
			$this->Session->setFlash(__('Tag was not deleted'));
		}
		if (!$this->_isRest()) $this->redirect(array('action' => 'index'));
	}

	public function view($id) {
		if ($this->_isRest()) {
			$contain = array('EventTag' => array('fields' => 'event_id'));
			$contain['AttributeTag'] = array('fields' => 'attribute_id');
			$tag = $this->Tag->find('first', array(
					'conditions' => array('id' => $id),
					'recursive' => -1,
					'contain' => $contain
			));
			if (empty($tag)) throw new MethodNotAllowedException('Invalid Tag');
			if (empty($tag['EventTag'])) $tag['Tag']['count'] = 0;
			else {
				$eventIDs = array();
				foreach ($tag['EventTag'] as $eventTag) {
					$eventIDs[] = $eventTag['event_id'];
				}
				$conditions = array('Event.id' => $eventIDs);
				if (!$this->_isSiteAdmin()) $conditions = array_merge(
						$conditions,
						array('OR' => array(
								array('AND' => array(
										array('Event.distribution >' => 0),
										array('Event.published =' => 1)
								)),
								array('Event.orgc_id' => $this->Auth->user('org_id'))
						)));
				$events = $this->Tag->EventTag->Event->find('all', array(
						'fields' => array('Event.id', 'Event.distribution', 'Event.orgc_id'),
						'conditions' => $conditions
				));
				$tag['Tag']['count'] = count($events);
			}
			unset($tag['EventTag']);
			if (empty($tag['AttributeTag'])) {
				$tag['Tag']['attribute_count'] = 0;
			} else {
				$attributeIDs = array();
				foreach ($tag['AttributeTag'] as $attributeTag) {
					$attributeIDs[] = $attributeTag['attribute_id'];
				}
				$conditions = array('Attribute.id' => $attributeIDs);
				if (!$this->_isSiteAdmin()) {
					$conditions = array_merge(
						$conditions,
						array('OR' => array(
							array('AND' => array(
								array('Attribute.deleted =' => 0),
								array('Attribute.distribution >' => 0),
								array('Event.distribution >' => 0),
								array('Event.published =' => 1)
							)),
							array('Event.orgc_id' => $this->Auth->user('org_id'))
						)));
				}
				$attributes = $this->Tag->AttributeTag->Attribute->find('all', array(
					'fields'     => array('Attribute.id', 'Attribute.deleted', 'Attribute.distribution', 'Event.id', 'Event.distribution', 'Event.orgc_id'),
					'contain'    => array('Event' => array('fields' => array('id', 'distribution', 'orgc_id'))),
					'conditions' => $conditions
				));
				$tag['Tag']['attribute_count'] = count($attributes);
			}
			unset($tag['AttributeTag']);
			$this->set('Tag', $tag['Tag']);
			$this->set('_serialize', 'Tag');
		} else throw new MethodNotAllowedException('This action is only for REST users.');

	}

	public function showEventTag($id) {
		$this->loadModel('EventTag');
		if (!$this->EventTag->Event->checkIfAuthorised($this->Auth->user(), $id)) {
			throw new MethodNotAllowedException('Invalid event.');
		}
		$this->loadModel('GalaxyCluster');
		$cluster_names = $this->GalaxyCluster->find('list', array('fields' => array('GalaxyCluster.tag_name'), 'group' => array('GalaxyCluster.tag_name')));
		$this->helpers[] = 'TextColour';
		$tags = $this->EventTag->find('all', array(
				'conditions' => array(
						'event_id' => $id,
						'Tag.name !=' => $cluster_names
				),
				'contain' => array('Tag'),
				'fields' => array('Tag.id', 'Tag.colour', 'Tag.name'),
		));
		$this->set('tags', $tags);
		$event = $this->Tag->EventTag->Event->find('first', array(
				'recursive' => -1,
				'fields' => array('Event.id', 'Event.orgc_id', 'Event.org_id', 'Event.user_id'),
				'conditions' => array('Event.id' => $id)
		));
		$this->set('event', $event);
		$this->layout = 'ajax';
		$this->render('/Events/ajax/ajaxTags');
	}

	public function showAttributeTag($id) {
		$this->helpers[] = 'TextColour';
		$this->loadModel('AttributeTag');

		$this->Tag->AttributeTag->Attribute->id = $id;
		if (!$this->Tag->AttributeTag->Attribute->exists()) throw new NotFoundException(__('Invalid attribute'));
		$this->Tag->AttributeTag->Attribute->read();
		$eventId = $this->Tag->AttributeTag->Attribute->data['Attribute']['event_id'];

		$attributeTags = $this->AttributeTag->find('all', array(
			'conditions' => array(
				'attribute_id' => $id
			),
			'contain' => array('Tag'),
			'fields' => array('Tag.id', 'Tag.colour', 'Tag.name'),
		));
		$event = $this->Tag->AttributeTag->Attribute->Event->find('first', array(
			'recursive' => -1,
			'fields' => array('Event.id', 'Event.orgc_id', 'Event.org_id', 'Event.user_id'),
			'conditions' => array('Event.id' => $eventId)
		));
		$this->set('event', $event);
		$this->set('attributeTags', $attributeTags);
		$this->set('attributeId', $id);
		$this->layout = 'ajax';
		$this->render('/Attributes/ajax/ajaxAttributeTags');
	}

	public function viewTag($id) {
		$tag = $this->Tag->find('first', array(
				'conditions' => array(
						'id' => $id
				),
				'recursive' => -1,
		));
		$this->layout = null;
		$this->set('tag', $tag);
		$this->set('id', $id);
		$this->render('ajax/view_tag');
	}


	public function selectTaxonomy($id, $attributeTag = false) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tagger']) throw new NotFoundException('You don\'t have permission to do that.');
		$favourites = $this->Tag->FavouriteTag->find('count', array('conditions' => array('FavouriteTag.user_id' => $this->Auth->user('id'))));
		$this->loadModel('Taxonomy');
		$options = $this->Taxonomy->find('list', array('conditions' => array('enabled' => true), 'fields' => array('namespace'), 'order' => array('Taxonomy.namespace ASC')));
		foreach ($options as $k => $option) {
			$tags = $this->Taxonomy->getTaxonomyTags($k, false, true);
			if (empty($tags)) unset($options[$k]);
		}
		if ($attributeTag !== false) {
			$this->set('attributeTag', true);
		}
		$this->set('object_id', $id);
		$this->set('options', $options);
		$this->set('favourites', $favourites);
		$this->render('ajax/taxonomy_choice');
	}

	public function selectTag($id, $taxonomy_id, $attributeTag = false) {
		if (!$this->_isSiteAdmin() && !$this->userRole['perm_tagger']) throw new NotFoundException('You don\'t have permission to do that.');
		$this->loadModel('Taxonomy');
		$expanded = array();
		if ($taxonomy_id === '0') {
			$options = $this->Taxonomy->getAllTaxonomyTags(true);
			$expanded = $options;
		} else if ($taxonomy_id === 'favourites') {
			$conditions = array('FavouriteTag.user_id' => $this->Auth->user('id'));
			$tags = $this->Tag->FavouriteTag->find('all', array(
				'conditions' => $conditions,
				'recursive' => -1,
				'contain' => array('Tag.name')
			));
			foreach ($tags as $tag) {
				$options[$tag['FavouriteTag']['tag_id']] = $tag['Tag']['name'];
				$expanded = $options;
			}
		} else if ($taxonomy_id === 'all') {
			$conditions = array('Tag.org_id' => array(0, $this->Auth->user('org_id')));
			if (Configure::read('MISP.incoming_tags_disabled_by_default')) {
				$conditions['Tag.hide_tag'] = 0;
			}
			$options = $this->Tag->find('list', array('fields' => array('Tag.name'), 'conditions' => $conditions));
			$expanded = $options;
		} else {
			$taxonomies = $this->Taxonomy->getTaxonomy($taxonomy_id);
			$options = array();
			foreach ($taxonomies['entries'] as $entry) {
				if (!empty($entry['existing_tag']['Tag'])) {
					$options[$entry['existing_tag']['Tag']['id']] = $entry['existing_tag']['Tag']['name'];
					$expanded[$entry['existing_tag']['Tag']['id']] = $entry['expanded'];
				}
			}
		}
		// Unset all tags that this user cannot use for tagging, determined by the org restriction on tags
		if (!$this->_isSiteAdmin()) {
			$banned_tags = $this->Tag->find('list', array(
					'conditions' => array(
							'NOT' => array(
									'Tag.org_id' => array(
											0,
											$this->Auth->user('org_id')
									)
							)
					),
					'fields' => array('Tag.id')
			));
			foreach ($banned_tags as $banned_tag) {
				unset($options[$banned_tag]);
				unset($expanded[$banned_tag]);
			}
		}
		if ($attributeTag !== false) {
			$this->set('attributeTag', true);
		}
		$this->set('object_id', $id);
		foreach ($options as $k => $v) {
			if (substr($v, 0, strlen('misp-galaxy:')) === 'misp-galaxy:') {
				unset($options[$k]);
			}
		}
		$this->set('options', $options);
		$this->set('expanded', $expanded);
		$this->set('custom', $taxonomy_id == 0 ? true : false);
		$this->render('ajax/select_tag');
	}

	public function tagStatistics($percentage = false, $keysort = false) {
		$result = $this->Tag->EventTag->find('all', array(
				'recursive' => -1,
				'fields' => array('count(EventTag.id) as count', 'tag_id'),
				'contain' => array('Tag' => array('fields' => array('Tag.name'))),
				'group' => array('tag_id')
		));
		$tags = array();
		$taxonomies = array();
		$totalCount = 0;
		$this->loadModel('Taxonomy');
		$temp = $this->Taxonomy->listTaxonomies(array('enabled' => true));
		foreach ($temp as $t) {
			if ($t['enabled']) $taxonomies[$t['namespace']] = 0;
		}
		foreach ($result as $r) {
			if ($r['Tag']['name'] == null) continue;
			$tags[$r['Tag']['name']] = $r[0]['count'];
			$totalCount += $r[0]['count'];
			foreach ($taxonomies as $taxonomy => $count) {
				if (substr(strtolower($r['Tag']['name']), 0, strlen($taxonomy)) === strtolower($taxonomy)) $taxonomies[$taxonomy] += $r[0]['count'];
			}
		}
		if ($keysort === 'true') {
			ksort($tags, SORT_NATURAL | SORT_FLAG_CASE);
			ksort($taxonomies, SORT_NATURAL | SORT_FLAG_CASE);
		} else {
			arsort($tags);
			arsort($taxonomies);
		}
		if ($percentage === 'true') {
			foreach ($tags as $tag => $count) {
				$tags[$tag] = round(100 * $count / $totalCount, 3) . '%';
			}
			foreach ($taxonomies as $taxonomy => $count) {
				$taxonomies[$taxonomy] = round(100 * $count / $totalCount, 3) . '%';
			}
		}
		$results = array('tags' => $tags, 'taxonomies' => $taxonomies);
		$this->autoRender = false;
		$this->layout = false;
		$this->set('data', $results);
		$this->set('flags', JSON_PRETTY_PRINT);
		$this->response->type('json');
		$this->render('/Servers/json/simple');
	}

	private function __findObjectByUuid($object_uuid, &$type) {
		$this->loadModel('Event');
		$object = $this->Event->find('first', array(
			'conditions' => array(
				'Event.uuid' => $object_uuid,
			),
			'fields' => array('Event.orgc_id', 'Event.id'),
			'recursive' => -1
		));
		$type = 'Event';
		if (!empty($object)) {
			if (!$this->_isSiteAdmin() && $object['Event']['orgc_id'] != $this->Auth->user('org_id')) {
					throw new MethodNotAllowedException('Invalid Target.');
			}
		} else {
			$type = 'Attribute';
			$object = $this->Event->Attribute->find('first', array(
				'conditions' => array(
					'Attribute.uuid' => $object_uuid,
				),
				'fields' => array('Attribute.id'),
				'recursive' => -1,
				'contain' => array('Event.orgc_id')
			));
			if (!empty($object)) {
				if (!$this->_isSiteAdmin() && $object['Event']['orgc_id'] != $this->Auth->user('org_id')) {
						throw new MethodNotAllowedException('Invalid Target.');
				}
			} else {
					throw new MethodNotAllowedException('Invalid Target.');
			}
		}
		return $object;
	}

	public function attachTagToObject($object_uuid, $tag) {
		if (!Validation::uuid($object_uuid)) {
			throw new InvalidArgumentException('Invalid UUID');
		}
		if (is_numeric($tag)) {
			$conditions = array('Tag.id' => $tag);
		} else {
			$conditions = array('LOWER(Tag.name) LIKE' => strtolower(trim($tag)));
		}
		$objectType = '';
		$object = $this->__findObjectByUuid($object_uuid, $objectType);
		$existingTag = $this->Tag->find('first', array('conditions' => $conditions, 'recursive' => -1));
		if (empty($existingTag)) {
			if (!is_numeric($tag)) {
				if (!$this->userRole['perm_tag_editor']) {
					throw new InvalidArgumentException('Tag not found and insufficient privileges to create it.');
				}
				$this->Tag->create();
				$this->Tag->save(array('Tag' => array('name' => $tag, 'colour' => $this->Tag->random_color())));
				$existingTag = $this->Tag->find('first', array('recursive' => -1, 'conditions' => array('Tag.id' => $this->Tag->id)));
			} else {
				throw new InvalidArgumentException('Invalid Tag.');
			}
		}
		if (!$this->_isSiteAdmin()) {
			if (!in_array($existingTag['Tag']['org_id'], array(0, $this->Auth->user('org_id')))) {
				throw new MethodNotAllowedException('Invalid Tag.');
			}
		}
		$this->loadModel($objectType);
		$connectorObject = $objectType . 'Tag';
		$existingAssociation = $this->$objectType->$connectorObject->find('first', array(
			'conditions' => array(
				strtolower($objectType) . '_id' => $object[$objectType]['id'],
				'tag_id' => $existingTag['Tag']['id']
			)
		));
		if (!empty($existingAssociation)) {
			throw new MethodNotAllowedException('Cannot attach tag, ' . $objectType . ' already has the tag attached.');
		}
		$this->$objectType->$connectorObject->create();
		$result = $this->$objectType->$connectorObject->save(array($connectorObject => array(
			strtolower($objectType) . '_id' => $object[$objectType]['id'],
			'tag_id' => $existingTag['Tag']['id']
		)));
		if ($result) {
			$message = 'Tag ' . $existingTag['Tag']['name'] . '(' . $existingTag['Tag']['id'] . ') successfully attached to ' . $objectType . '(' . $object[$objectType]['id'] . ').';
			return $this->RestResponse->saveSuccessResponse('Tags', 'attachTagToObject', false, $this->response->type(), $message);
		} else {
			return $this->RestResponse->saveFailResponse('Tags', 'attachTagToObject', false, 'Failed to attach tag to object.', $this->response->type());
		}
	}

	public function removeTagFromObject($object_uuid, $tag) {
		if (!Validation::uuid($object_uuid)) {
			throw new InvalidArgumentException('Invalid UUID');
		}
		if (is_numeric($tag)) {
			$conditions = array('Tag.id' => $tag);
		} else {
			$conditions = array('LOWER(Tag.name) LIKE' => strtolower(trim($tag)));
		}
		$existingTag = $this->Tag->find('first', array('conditions' => $conditions, 'recursive' => -1));
		if (empty($existingTag)) {
			throw new MethodNotAllowedException('Invalid Tag.');
		}
		$objectType = '';
		$object = $this->__findObjectByUuid($object_uuid, $objectType);
		if (empty($object)) {
			throw new MethodNotAllowedException('Invalid Target.');
		}
		$connectorObject = $objectType . 'Tag';
		$this->loadModel($objectType);
		$existingAssociation = $this->$objectType->$connectorObject->find('first', array(
			'conditions' => array(
				strtolower($objectType) . '_id' => $object[$objectType]['id'],
				'tag_id' => $existingTag['Tag']['id']
			)
		));
		if (empty($existingAssociation)) {
			throw new MethodNotAllowedException('Could not remove tag as it is not attached to the target ' . $objectType);
		}
		$result = $this->$objectType->$connectorObject->delete($existingAssociation[$connectorObject]['id']);
		if ($result) {
			$message = 'Tag ' . $existingTag['Tag']['name'] . '(' . $existingTag['Tag']['id'] . ') successfully removed from ' . $objectType . '(' . $object[$objectType]['id'] . ').';
			return $this->RestResponse->saveSuccessResponse('Tags', 'removeTagFromObject', false, $this->response->type(), $message);
		} else {
			return $this->RestResponse->saveFailResponse('Tags', 'removeTagFromObject', false, 'Failed to remove tag from object.', $this->response->type());
		}
	}
}
