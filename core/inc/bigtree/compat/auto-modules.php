<?php
	/*
		Class: BigTreeAutoModule
			Handles functions for auto module forms / views created in Developer.
	*/

	class BigTreeAutoModule {
		
		/*
			Function: cacheNewItem
				Caches a new database entry by investigating associated views.
			
			Parameters:
				id - The id of the new item.
				table - The table the new item is in.
				pending - Whether this is actually a pending entry or not.
			
			See Also:
				<recacheItem>
		*/
		
		static function cacheNewItem($id,$table,$pending = false) {
			BigTree\ModuleView::cacheForAll($id, $table, $pending);
		}
		
		static function cacheRecord() {
			trigger_error("BigTreeAutoModule::cacheRecord is not meant to be called directly. Please use BigTree\\ModuleView::cacheItem", E_USER_WARNING);

			return false;
		}
		
		/*
			Function: cacheViewData
				Grabs all the data from a view and does parsing on it based on automatic assumptions and manual parsers.
			
			Parameters:
				view - The view entry to cache data for.
		*/
		
		static function cacheViewData($view) {
			$view = new BigTree\ModuleView($view);
			$view->cacheAllData();
		}
		
		/*
			Function: changeExists
				Checks to see if a change exists for a given item in the bigtree_pending_changes table.

			Parameters:
				table - The table the item is from.
				id - The ID of the item.

			Returns:
				true or false
		*/

		static function changeExists($table,$id) {
			return BigTree\PendingChange::exists($table, $id);
		}

		/*
			Function: clearCache
				Clears the cache of a view or all views with a given table.
			
			Parameters:
				view - The view id or view entry to clear the cache for or a table to find all views for (and clear their caches).
		*/
		
		static function clearCache($view) {
			if (is_array($view) || is_numeric($view)) {
				$view = new BigTree\ModuleView($view);
				$view->clearCache();
			} else {
				BigTree\ModuleView::clearCacheForTable($view);
			}
		}
		
		/*
			Function: createItem
				Creates an entry in the database for an auto module form.
		
			Parameters:
				table - The table to put the data in.
				data - An array of form data to enter into the table. This function determines what data in the array applies to a column in the database and discards the rest.
				many_to_many - Many to many relationship entries.
				tags - Tags for the entry.
			
			Returns:
				The id of the new entry in the database.
		*/

		static function createItem($table,$data,$many_to_many = array(),$tags = array()) {			
			$table_description = BigTree::describeTable($table);
			$insert_values = array();

			foreach ($data as $key => $val) {
				if (array_key_exists($key,$table_description["columns"])) {
					// For backwards compatibility we'll leave this
					if ($val === "NULL") {
						$val = null;
					} 

					$insert_values[$key] = $val;
				}
			}
			
			// Insert, if there's a failure return false instead of doing the rest
			$id = SQL::insert($table,$insert_values);
			if (!$id) {
				return false;
			}

			// Handle many to many
			foreach ($many_to_many as $mtm) {
				if (is_array($mtm["data"])) {
					// Find out what columns we have
					$table_description = BigTree::describeTable($mtm["table"]);

					// Setup position
					$x = count($mtm["data"]);

					foreach ($mtm["data"] as $position => $item) {
						// Setup the insert
						$insert_values = array(
							$mtm["my-id"] => $id,
							$mtm["other-id"] => $item
						);

						// Add position if this is a positioned relationship
						if (isset($table_description["columns"]["position"])) {
							$insert_values["position"] = $x;
						}

						// Insert it
						SQL::insert($mtm["table"],$insert_values);

						// Decrease position
						$x--;
					}
				}
			}

			// Handle the tags
			SQL::delete("bigtree_tags_rel",array("table" => $table, "entry" => $id));
			if (is_array($tags)) {
				// Strip out dupes
				$tags = array_unique($tags);

				foreach ($tags as $tag) {
					SQL::insert("bigtree_tags_rel",array(
						"table" => $table,
						"entry" => $id,
						"tag" => $tag
					));
				}
			}
			
			self::cacheNewItem($id,$table);			
			self::track($table,$id,"created");

			return $id;
		}
		
		/*
			Function: createPendingItem
				Creates an entry in the bigtree_pending_changes table for an auto module form.
		
			Parameters:
				module - The module for the entry.
				table - The table to put the data in.
				data - An array of form data to enter into the table. This function determines what data in the array applies to a column in the database and discards the rest.
				many_to_many - Many to many relationship entries.
				tags - Tags for the entry.
				publish_hook - A function to call when this change is published from the Dashboard.
			
			Returns:
				The id of the new entry in the bigtree_pending_changes table.
		*/

		static function createPendingItem($module,$table,$data,$many_to_many = array(),$tags = array(),$publish_hook = null) {
			global $admin;
			
			foreach ($data as $key => $val) {
				if ($val === "NULL") {
					$data[$key] = "";
				}
				if (is_array($val)) {
					$data[$key] = BigTree::translateArray($val);
				}
			}

			$id = SQL::insert("bigtree_pending_changes",array(
				"user" => $admin->ID,
				"table" => $table,
				"changes" => $data,
				"mtm_changes" => $many_to_many,
				"tags_changes" => $tags,
				"module" => $module,
				"publish_hook" => $publish_hook
			));
			
			self::cacheNewItem($id,$table,true);
			self::track($table,"p$id","created-pending");
			
			return $id;
		}
		
		/*
			Function: deleteItem
				Deletes an item from the given table and removes any pending changes, then uncaches it from its views.
			
			Parameters:
				table - The table to delete an entry from.
				id - The id of the entry.
		*/

		static function deleteItem($table,$id) {
			SQL::delete($table,$id);
			SQL::delete("bigtree_pending_changes",array("table" => $table,"item_id" => $id));

			self::uncacheItem($id,$table);
			self::track($table,$id,"deleted");
		}
		
		/*
			Function: deleteItem
				Deletes a pending item from bigtree_pending_changes and uncaches it.
			
			Parameters:
				table - The table the entry would have been in (should it have ever been published).
				id - The id of the pending entry.
		*/
		
		static function deletePendingItem($table,$id) {
			SQL::delete("bigtree_pending_changes",$id);

			self::uncacheItem("p$id",$table);
			self::track($table,"p$id","deleted-pending");
		}

		/*
			Function: getDependentViews
				Returns all views that have a dependence on a given table.

			Parameters:
				table - Table name

			Returns:
				An array of view rows from bigtree_module_interfaces
		*/

		static function getDependentViews($table) {
			$table = SQL::escape($table);
			return SQL::fetchAll("SELECT * FROM bigtree_module_interfaces 
											  WHERE `type` = 'view' AND `settings` LIKE '%$table%'");
		}

		/*
			Function: getEditAction
				Returns a module action for the given module and form IDs.

			Parameters:
				module - Module ID
				form - Form ID

			Returns:
				A bigtree_module_actions entry.
		*/

		static function getEditAction($module,$form) {
			$module = new BigTree\Module($module);
			$action = $module->getEditAction($form);

			return $action ? $action->Array : false;
		}

		/*
			Function: getEmbedForm
				Returns a module embeddable form.
			
			Parameters:
				id - The id of the form.
			
			Returns:
				A module form entry with fields decoded.
		*/

		static function getEmbedForm($id) {
			$form = new BigTree\ModuleEmbedForm($id);

			return $form ? $form->Array : false;
		}

		/*
			Function: getEmbedFormByHash
				Returns a module embeddable form.
			
			Parameters:
				hash - The hash of the form.
			
			Returns:
				A module form entry with fields decoded.
		*/

		static function getEmbedFormByHash($hash) {
			$form = BigTree\ModuleEmbedForm::getByHash($hash);

			return $form ? $form->Array : false;
		}
		
		/*
			Function: getFilterQuery
				Returns a query string that is used for searching views based on group permissions.
				Can only be called when logged into the admin.
			
			Parameters:
				view - The view to create a filter for.
			
			Returns:
				A set of MySQL statements that filter out information the user cannot access.
		*/
		
		static function getFilterQuery($view) {
			$view = new BigTree\ModuleView($view);

			return $view ? $view->FilterQuery : "";
		}
		
		/*
			Function: getForm
				Returns a module form.
			
			Parameters:
				id - The id of the form.
			
			Returns:
				A module form entry with fields decoded.
		*/

		static function getForm($id) {
			$form = new BigTree\ModuleForm($id);

			return $form ? $form->Array : false;
		}
		
		/*
			Function: getGroupsForView
				Returns all groups in the view cache for a view.
			
			Parameters:
				view - The view entry.
			
			Returns:
				An array of groups.
		*/
		
		static function getGroupsForView($view) {
			$view = new BigTree\ModuleView($view);

			return $view ? $view->Groups : false;
		}

		/*
			Function: getInterface
				Gets a module interface. If the interface is a core type, the related type will be returned.

			Parameters:
				id - The interface ID.

			Returns:
				An interface array (or specialty type for forms, embeddable forms, views, and reports).
		*/

		static function getInterface($id) {
			$interface = SQL::fetch("SELECT * FROM bigtree_module_interfaces WHERE id = ?", $id);
			if ($interface["type"] == "form") {
				$form = static::getForm($id);
				$form["interface_type"] = "form";
				return $form;
			} elseif ($interface["type"] == "embeddable-form") {
				$form = static::getEmbedForm($id);
				$form["interface_type"] = "embeddable-form";
				return $form;
			} elseif ($interface["type"] == "view") {
				$view = static::getView($id);
				$view["interface_type"] = "view";
				return $view;
			} elseif ($interface["type"] == "report") {
				$report = static::getReport($id);
				$report["interface_type"] = "report";
				return $report;
			} else {
				$interface["settings"] = json_decode($interface["settings"],true);
				return $interface;
			}
		}

		/*
			Function: getItem
				Returns an entry from a table with all its related information.
				If a pending ID is passed in (prefixed with a p) getPendingItem is called instead.

			Parameters:
				table - The table to pull the entry from.
				id - The id of the entry.

			Returns:
				An array with the following key/value pairs:
				"item" - The entry from the table with pending changes already applied.
				"tags" - A list of tags for the entry.
				
				Returns false if the entry could not be found.
		*/

		static function getItem($table,$id) {
			// The entry is pending if there's a "p" prefix on the id
			if (substr($id,0,1) == "p") {
				return self::getPendingItem($table,$id);
			}

			// Otherwise it's a live entry
			$item = SQL::fetch("SELECT * FROM `$table` WHERE id = ?", $id);
			if (!is_array($item)) {
				return false;
			}
			$tags = self::getTagsForEntry($table,$id);

			// Process the internal page links, turn json_encoded arrays into arrays.
			foreach ($item as $key => $val) {
				$array_val = @json_decode($val, true);

				if (is_array($array_val)) {
					$item[$key] = BigTree::untranslateArray($array_val);
				} else {
					$item[$key] = BigTreeCMS::replaceInternalPageLinks($val);
				}
			}

			return array("item" => $item, "tags" => $tags);
		}
		
		/*
			Function: getModuleForForm
				Returns the associated module id for the given form.
				DEPRECATED - Please use getModuleForInterface.
			
			Parameters:
				form - Either a form entry or form id.
			
			Returns:
				The id of the module the form is a member of.

			See Also:
				<getModuleForInterface>
		*/
		
		static function getModuleForForm($form) {
			return self::getModuleForInterface($form);
		}

		/*
			Function: getModuleForInterface
				Returns the associated module id for the given interface.
			
			Parameters:
				interface - Either a interface array or interface id.
			
			Returns:
				The id of the module the interface is a member of.
		*/
		
		static function getModuleForInterface($interface) {
			// May already have the info we need
			if (is_array($interface)) {
				if ($interface["module"]) {
					return $interface["module"];
				}
				$interface = $interface["id"];
			}

			return SQL::fetchSingle("SELECT module FROM bigtree_module_actions WHERE interface = ?", $interface);
		}
		
		/*
			Function: getModuleForView
				Returns the associated module id for the given view.
				DEPRECATED - Please use getModuleForInterface.
			
			Parameters:
				view - Either a view entry or view id.
			
			Returns:
				The id of the module the view is a member of.

			See Also:
				<getModuleForInterface>
		*/

		static function getModuleForView($view) {
			return self::getModuleForInterface($view);
		}
		
		/*
			Function: getPendingItem
				Gets an entry from a table with all its related information and pending changes applied.
			
			Parameters:
				table - The table to pull the entry from.
				id - The id of the entry.
			
			Returns:
				An array with the following key/value pairs:
				"item" - The entry from the table with pending changes already applied.
				"mtm" - A list of many to many pending changes.
				"tags" - A list of tags for the entry.
				"status" - Whether the item is pending ("pending"), published ("published"), or has changes ("updated") awaiting publish.
				
				Returns false if the entry could not be found.
		*/

		static function getPendingItem($table,$id) {
			$status = "published";
			$many_to_many = array();
			$owner = false;

			// The entry is pending if there's a "p" prefix on the id
			if (substr($id,0,1) == "p") {
				$change = SQL::fetch("SELECT * FROM bigtree_pending_changes WHERE id = ?", substr($id,1));
				if (!$change) {
					return false;
				}
				
				$item = json_decode($change["changes"],true);
				$many_to_many = json_decode($change["mtm_changes"],true);
				$temp_tags = json_decode($change["tags_changes"],true);
				
				// If we have temporary tag IDs, get the full list
				if (array_filter((array)$temp_tags)) {
					// Add the query
					array_unshift($temp_tags, "SELECT * FROM bigtree_tags 
											   WHERE ".implode(" OR ", array_fill(0, count($temp_tags), "id = ?")));
					$tags = call_user_func_array(array(BigTreeCMS::$DB,"fetchAll"), $temp_tags);
				} else {
					$tags = array();
				}

				$status = "pending";
				$owner = $change["user"];
				
			// Otherwise it's a live entry
			} else {
				$item = SQL::fetch("SELECT * FROM `$table` WHERE id = ?", $id);
				if (!$item) {
					return false;
				}
				
				// Apply changes that are pending
				$change = SQL::fetch("SELECT * FROM bigtree_pending_changes
												  WHERE `table` = ? AND `item_id` = ?", $table, $id);
				if ($change) {
					$status = "updated";

					// Apply changes back
					$changes = json_decode($change["changes"],true);
					foreach ($changes as $key => $val) {
						$item[$key] = $val;
					}

					$many_to_many = json_decode($change["mtm_changes"],true);
					$temp_tags = json_decode($change["tags_changes"],true);
					
					// If we have temporary tag IDs, get the full list
					if (array_filter((array)$temp_tags)) {
						// Add the query
						array_unshift($temp_tags, "SELECT * FROM bigtree_tags 
												   WHERE ".implode(" OR ", array_fill(0, count($temp_tags), "id = ?")));
						$tags = call_user_func_array(array(BigTreeCMS::$DB,"fetchAll"), $temp_tags);
					} else {
						$tags = array();
					}

				// If there's no pending changes, just pull the tags
				} else {
					$tags = self::getTagsForEntry($table,$id);
				}
			}
			
			// Process the internal page links, turn json_encoded arrays into arrays.
			foreach ($item as $key => $val) {
				if (is_array($val)) {
					$item[$key] = BigTree::untranslateArray($val);
				} else {
					$array_val = @json_decode($val, true);
					if (is_array($array_val)) {
						$item[$key] = BigTree::untranslateArray($array_val);
					} else {
						$item[$key] = BigTreeCMS::replaceInternalPageLinks($val);
					}
				}
			}

			return array("item" => $item, "mtm" => $many_to_many, "tags" => $tags, "status" => $status, "owner" => $owner);
		}

		/*
			Function: getRelatedFormForReport
				Returns the form for the same table as the given report.
			
			Parameters:
				report - A report entry.
			
			Returns:
				A form entry with fields decoded.
		*/

		static function getRelatedFormForReport($report) {
			$report = new BigTree\ModuleReport($report);
			$form = $report->RelatedModuleForm;

			return $form ? $form->Array : false;
		}
		
		/*
			Function: getRelatedFormForView
				Returns the form for the same table as the given view.
			
			Parameters:
				view - A view entry.
			
			Returns:
				A form entry with fields decoded.
		*/

		static function getRelatedFormForView($view) {
			$view = new BigTree\ModuleView($view);
			$form = $view->RelatedModuleForm;

			return $form ? $form->Array : false;
		}
		
		/*
			Function: getRelatedViewForForm
				Returns the view for the same table as the given form.
			
			Parameters:
				form - A form entry.
			
			Returns:
				A view entry.
		*/

		static function getRelatedViewForForm($form) {
			$form = new BigTree\ModuleForm($form);
			$view = $form->RelatedModuleView;

			return $view ? $view->Array : false;
		}

		/*
			Function: getRelatedViewForReport
				Returns the view for the same table as the given report.
			
			Parameters:
				report - A report entry.
			
			Returns:
				A view entry.
		*/

		static function getRelatedViewForReport($report) {
			$report = new BigTree\ModuleReport($report);
			$view = $report->RelatedModuleView;

			return $view ? $view->Array : false;
		}

		/*
			Function: getReport
				Returns a report with the filters and fields decoded.

			Parameters:
				id - The ID of the report

			Returns:
				An array of report information.
		*/

		static function getReport($id) {
			$report = new BigTree\ModuleReport($id);

			return $report ? $report->Array : false;
		}

		/*
			Function: getReportResults
				Returns rows from the table that match the filters provided.

			Parameters:
				report - A report interface entry.
				view - A view interface array.
				form - A form interface array.
				filters - The submitted filters to run.
				sort_field - The field to sort by.
				sort_direction - The direction to sort by.

			Returns:
				An array of entries from the report's table.
		*/

		static function getReportResults($report,$view,$form,$filters,$sort_field = "id",$sort_direction = "DESC") {
			$report = new BigTree\ModuleReport($report);

			return $report->getResults($view, $form, $filters, $sort_field, $sort_direction);
		}
		
		/*
			Function: getSearchResults
				Returns results from the bigtree_module_view_cache table.
		
			Parameters:
				view - The view to pull data for.
				page - The page of data to retrieve.
				query - The query string to search against.
				sort - The column and direction to sort.
				group - The group to pull information for.
		
			Returns:
				An array containing "pages" with the number of result pages and "results" with the results for the given page.
		*/
		
		static function getSearchResults($view,$page = 1,$query = "",$sort = "id DESC",$group = false) {
			// Check to see if we've cached this table before.
			self::cacheViewData($view);
			
			$search_parts = explode(" ",$query);
			$view_column_count = count($view["fields"]);
			$per_page = $view["options"]["per_page"] ? $view["options"]["per_page"] : BigTreeAdmin::$PerPage;			
			$query = "SELECT * FROM bigtree_module_view_cache WHERE view = '".$view["id"]."'".self::getFilterQuery($view);
	
			if ($group !== false) {
				$query .= " AND group_field = '".SQL::escape($group)."'";
			}
			
			// Add all the pieces of the query to check against the columns in the view
			foreach ($search_parts as $part) {
				$part = SQL::escape($part);

				$query_parts = array();
				for ($x = 1; $x <= $view_column_count; $x++) {
					$query_parts[] = "column$x LIKE '%$part%'";
				}

				if (count($query_parts)) {
					$query .= " AND (".implode(" OR ",$query_parts).")";
				}
			}
			
			// Find how many pages are returned from this search
			$total = SQL::fetchSingle(str_replace("SELECT *","SELECT COUNT(*)",$query));
			$pages = ceil($total / $per_page);
			$pages = $pages ? $pages : 1;

			// Get the correct column name for sorting
			if (strpos($sort,"`") !== false) { // New formatting
				$sort_field = BigTree::nextSQLColumnDefinition(substr($sort,1));
				$sort_pieces = explode(" ",$sort);
				$sort_direction = end($sort_pieces);
			} else { // Old formatting
				list($sort_field,$sort_direction) = explode(" ",$sort);
			}

			// Figure out whether we need to cast the column we're sorting by as numeric so that 2 comes before 11
			if ($sort_field != "id") {
				$x = 0;
				if (isset($view["fields"][$sort_field]["numeric"]) && $view["fields"][$sort_field]["numeric"]) {
					$convert_numeric = true;
				} else {
					$convert_numeric = false;
				}
				foreach ($view["fields"] as $field => $options) {
					$x++;
					if ($field == $sort_field) {
						$sort_field = "column$x";
					}
				}
				// If we didn't find a column, let's assume it's the default sort field.
				if (substr($sort_field,0,6) != "column") {
					$sort_field = "sort_field";
				}
				if ($convert_numeric) {
					$sort_field = "CONVERT(".$sort_field.",SIGNED)";
				}
			} else {
				$sort_field = "CONVERT(id,UNSIGNED)";
			}

			if (strtolower($sort) == "position desc, id asc") {
				$sort_field = "position DESC, id ASC";
				$sort_direction = "";
			} else {
				$sort_direction = (strtolower($sort_direction) == "asc") ? "ASC" : "DESC";
			}
			
			if ($page === "all") {
				$results = SQL::fetchAll($query." ORDER BY $sort_field $sort_direction");
			} else {
				$results = SQL::fetchAll($query." ORDER BY $sort_field $sort_direction LIMIT ".(($page - 1) * $per_page).",$per_page");
			}
	
			return array("pages" => $pages, "results" => $results);
		}
		
		/*
			Function: getTagsForEntry
				Returns the tags for an entry.
				
			Parameters:
				table - The table the entry is in.
				id - The id of the entry.
			
			Returns:
				An array ot tags from bigtree_tags.
		*/
		
		static function getTagsForEntry($table,$id) {
			return SQL::fetchAll("SELECT bigtree_tags.* FROM bigtree_tags JOIN bigtree_tags_rel 
											  ON bigtree_tags_rel.tag = bigtree_tags.id 
											  WHERE bigtree_tags_rel.`table` = ? AND bigtree_tags_rel.entry = ? 
											  ORDER BY bigtree_tags.tag ASC", $table, $id);
		}
		
		/*
			Function: getView
				Returns a view.
			
			Parameters:
				id - The id of the view.
				decode_ipl - Whether we want to decode internal page link on the preview url (defaults to true)
				
			Returns:
				A view entry with actions, options, and fields decoded.  fields also receive a width column for the view.
		*/

		static function getView($id) {
			$view = new BigTree\ModuleView($id);

			return $view ? $view->Array : false;
		}
		
		/*
			Function: getViewData
				Gets a list of data for a view.
			
			Parameters:
				view - The view entry to pull data for.
				sort - The sort direction, defaults to most recent.
				type - Whether to get only active entries, pending entries, or both.
				group - The group to get data for (defaults to all).
			
			Returns:
				An array of items from bigtree_module_view_cache.
		*/
		
		static function getViewData($view,$sort = "id DESC",$type = "both",$group = false) {
			$view = new BigTree\ModuleView($view);

			return $view->getData($sort,$type,$group);
		}
		
		/*
			Function: getViewDataForGroup
				Gets a list of data for a view in a given group.
			
			Parameters:
				view - The view entry to pull data for.
				group - The group to get data for.
				sort - The sort direction, defaults to most recent.
				type - Whether to get only active entries, pending entries, or both.
			
			Returns:
				An array of items from bigtree_module_view_cache.
		*/
		
		static function getViewDataForGroup($view,$group,$sort,$type = "both") {
			return static::getViewData($view,$sort,$type,$group);
		}
		
		/*
			Function: getViewForTable
				Gets a view for a given table for showing change lists in Pending Changes.
			
			Parameters:
				table - Table name.
			
			Returns:
				A view entry with options, and fields decoded and field widths set for Pending Changes.
		*/
		
		static function getViewForTable($table) {
			$view = SQL::fetch("SELECT * FROM bigtree_module_interfaces WHERE `type` = 'view' AND `table` = ?", $table);

			if (!$view) {
				return false;
			}

			$view = new BigTree\ModuleView($view);

			return $view->getArray($view->PreviewURL ? 578 : 633);
		}
		
		/*
			Function: parseViewData
				Parses data and returns the parsed columns (runs parsers and populated lists).
			
			Parameters:
				view - The view to parse items for.
				items - An array of entries to parse.
			
			Returns:
				An array of parsed entries.
		*/

		static function parseViewData($view,$items) {
			$view = new BigTree\ModuleView($view);

			return $view->parseData($items);
		}

		/*
			Function: publishPendingItem
				Publishes a pending item and caches it.
				
			Parameters:
				table - The table to store the entry in.
				id - The id of the pending entry.
				data - The form data to create an entry with.
				many_to_many - Many to Many information
				tags - Tag information
			
			Returns:
				The id of the new entry.
		*/
		
		static function publishPendingItem($table,$id,$data,$many_to_many = array(),$tags = array()) {
			self::deletePendingItem($table,$id);
			return self::createItem($table,$data,$many_to_many,$tags);
		}
		
		/*
			Function: recacheItem
				Re-caches a database entry.
			
			Parameters:
				id - The id of the entry.
				table - The table the entry is in.
				pending - Whether the entry is pending or not.
			
			See Also:
				<cacheNewItem>
		*/
		
		static function recacheItem($id,$table,$pending = false) {
			return BigTree\ModuleView::cacheForAll($id, $table, $pending);
		}

		/*
			Function: sanitizeData
				Processes form data into values understandable by the MySQL table.
			
			Parameters:
				table - The table to sanitize data for
				data - Array of key->value pairs
				existing_description - If the table has already been described, pass it in instead of making sanitizeData do it twice. (defaults to false)
			
			Returns:
				Array of data safe for MySQL.
		*/	
		
		static function sanitizeData($table,$data,$existing_description = false) {
			return BigTree\SQL::prepareData($table, $data, $existing_description);
		}

		/*
			Function: submitChange
				Creates a change request for an item and caches it.
				Can only be called when logged into the admin.
			
			Parameters:
				module - The module for the entry.
				table - The table the entry is stored in.
				id - The id of the entry.
				data - The change request data.
				many_to_many - The many to many changes.
				tags - The tag changes.
				publish_hook - A function to call when this change is published from the Dashboard.
			
			Returns:
				The id of the pending change.
		*/
		
		static function submitChange($module,$table,$id,$data,$many_to_many = array(),$tags = array(),$publish_hook = null) {
			global $admin;
			if (!isset($admin) || get_class($admin) != "BigTreeAdmin" || !$admin->ID) {
				trigger_error("BigTreeAutoModule::submitChange must be called by a logged in user.",E_USER_ERROR);
			}

			// If this is already a pending change we have no original item to compare to
			if (substr($id,0,1) == "p") {
				$existing = $id;
			} else {
				// Only save what's different between the original and the new changes
				$original = SQL::fetch("SELECT * FROM `$table` WHERE id = ?", $id);
				foreach ($data as $key => $val) {
					if ($val === "NULL") {
						$data[$key] = "";
					}
					if ($original && $original[$key] === $val) {
						unset($data[$key]);
					}
				}

				// See if we have another pending change that we're overwriting
				$existing = SQL::fetchSingle("SELECT id FROM bigtree_pending_changes 
														  WHERE `table` = ? AND item_id = ?", $table, $id);
			}

			// Overwriting an existing pending change
			if ($existing) {
				SQL::update("bigtree_pending_changes",$existing,array(
					"changes" => $data,
					"mtm_changes" => $many_to_many,
					"tags_changes" => $tags,
					"user" => $admin->ID
				));
				
				// If the id has a "p" it's still pending and we need to recache over the pending one.
				if (substr($id,0,1) == "p") {
					self::recacheItem(substr($id,1),$table,true);
				} else {
					self::recacheItem($id,$table);					
				}
				
				$admin->track($table,$id,"updated-draft");
				return $existing["id"];

			// Creating a new pending change
			} else {
				$change_id = SQL::insert("bigtree_pending_changes",array(
					"user" => $admin->ID,
					"table" => $table,
					"item_id" => $id,
					"changes" => $data,
					"mtm_changes" => $many_to_many,
					"tags_changes" => $tags,
					"module" => $module,
					"publish_hook" => $publish_hook
				));

				self::recacheItem($id,$table);				
				$admin->track($table,$id,"saved-draft");
				return $change_id;
			}
		}

		/*
			Function: track
				Used internally by the class to facilitate audit trail tracking when a logged in user is making a call.

			Parameters:
				table - The table that is being changed
				id - The id of the record being changed
				action - The action being taken
		*/

		static function track($table,$id,$action) {
			BigTree\AuditTrail::track($table, $id, $action);
		}
		
		/*
			Function: uncacheItem
				Removes a database entry from the view cache.
			
			Parameters:
				id - The id of the entry.
				table - The table the entry is in.
		*/
		
		static function uncacheItem($id,$table) {
			BigTree\ModuleView::uncacheForAll($id, $table);
		}

		/*
			Function: updateItem
				Update an entry and cache it.
			
			Parameters:
				table - The table the entry is in.
				id - The id of the entry.
				data - The data to update in the entry.
				many_to_many - Many To Many information
				tags - Tag information.
		*/
		
		static function updateItem($table,$id,$data,$many_to_many = array(),$tags = array()) {
			// Find out what columns a table has so we don't fail to update
			$table_description = BigTree::describeTable($table);

			$update_columns = array();
			foreach ($data as $key => $val) {
				if (array_key_exists($key,$table_description["columns"])) {
					if (is_array($val)) {
						$val = BigTree::translateArray($val);
					}
					$update_columns[$key] = $val;
				}
			}

			// Do the update
			SQL::update($table,$id,$update_columns);

			// Handle many to many
			if (!empty($many_to_many)) {
				foreach ($many_to_many as $mtm) {
					// Delete existing
					SQL::delete($mtm["table"],array($mtm["my-id"] => $id));

					if (is_array($mtm["data"])) {
						// Describe table to see if it's positioned
						$table_description = BigTree::describeTable($mtm["table"]);

						$position = count($mtm["data"]);
						foreach ($mtm["data"] as $item) {
							$mtm_insert_data = array(
								$mtm["my-id"] => $id,
								$mtm["other-id"] => $item
							);

							// If we're using a positioned table, add it while decreasing the position value
							if (isset($table_description["columns"]["position"])) {
								$mtm_insert_data["position"] = $position--;
							}

							SQL::insert($mtm["table"],$mtm_insert_data);
						}
					}
				}
			}

			// Handle the tags
			SQL::delete("bigtree_tags_rel",array("table" => $table, "entry" => $id));
			if (!empty($tags)) {
				foreach ($tags as $tag) {
					SQL::insert("bigtree_tags_rel",array(
						"table" => $table,
						"entry" => $id,
						"tag" => $tag
					));
				}
			}
			
			// Clear out any pending changes.
			SQL::delete("bigtree_pending_changes",array("item_id" => $id, "table" => $table));
			
			if ($table != "bigtree_pages") {
				self::recacheItem($id,$table);
			}
			
			self::track($table,$id,"updated");
		}

		/*
			Function: updatePendingItemField
				Update a pending item's field with a given value.
			
			Parameters:
				id - The id of the entry.
				field - The field to change.
				value - The value to set.
		*/
		
		static function updatePendingItemField($id,$field,$value) {
			$changes = json_decode(SQL::fetchSingle("SELECT changes FROM bigtree_pending_changes WHERE id = ?", $id), true);

			if (is_array($value)) {
				$value = BigTree::translateArray($value);
			} else {
				$value = BigTreeCMS::replaceInternalPageLinks($value);
			}
			$changes[$field] = $value;

			SQL::update("bigtree_pending_changes",$id,array("changes" => $changes));
		}

		/*
			Function: validate
				Validates a form element based on its validation requirements.
			
			Parameters:
				data - The form's posted data for a given field.
				type - Validation requirements (required, numeric, email, link).
		
			Returns:
				True if validation passed, otherwise false.
			
			See Also:
				<errorMessage>
		*/
		
		static function validate($data,$type) {
			return BigTree\Field::validate($data,$type);
		}

		/*
			Function: validationErrorMessage
				Returns an error message for a form element that failed validation.
			
			Parameters:
				data - The form's posted data for a given field.
				type - Validation requirements (required, numeric, email, link).
		
			Returns:
				A string containing reasons the validation failed.
				
			See Also:
				<validate>
		*/
		
		static function validationErrorMessage($data,$type) {
			return BigTree\Field::validationErrorMessage($data,$type);
		}
	}
