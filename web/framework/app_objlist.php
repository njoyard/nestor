<?

/*
This file is part of domserver.

domserver is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

domserver is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with domserver.  If not, see <http://www.gnu.org/licenses/>.
*/

class ObjectListActionIcon extends ImageElement
{
    function __construct($app, $id, $icon_name)
    {
        parent::__construct($app, $id, $app->skin->icon($icon_name));
    }
}

class ObjectListActionCell extends AppElement
{
    function __construct($app, $id, $settings, $data)
    {
        $this->s = $settings;
        $this->data = $data;
        $this->objref = $data["0ref"];
        
        if (!isset($this->s["actions"])) $this->s["actions"] = array();
        
        parent::__construct($app, $id);
    }
    
    function init()
    {
        $this->icons = array();
        
        $this->displayed = $this->load_data("displayed", array());
        
        foreach ($this->s["actions"] as $action => $desc)
        {
            $aid = str_replace("-", "", $action);
            $this->icons[$action] = new IconElement($this->app, "{$this->id}_$aid", $desc["icon"]);
        }
        
    }
    
    function render()
    {
        $this->set_css("text-overflow", "ellipsis");
        
        $this->displayed = array();
        
        foreach ($this->icons as $action => $icon)
        {
            $this->add_child($icon);
            $icon->set_dom("title", $this->s["actions"][$action]["title"]);
            $icon->set_css("cursor", "hand");
            $icon->set_handler("onclick", $this, "click_action", $action);
        }
        
        $this->update(TRUE);
    }
    
    function update($first=FALSE)
    {
        $displayed = array();
        
        foreach ($this->s["actions"] as $action => $desc)
        {
            if (isset($this->s["action_filter"]))
                $display = call_user_func($this->s["action_filter"], $action, $this->objref, $this->data);
            else
                $display = TRUE;
                
            $icon = $this->icons[$action];
            
            if ($display)
            {
                $displayed[] = $action;
                if (!in_array($action, $this->displayed))
                {
                    $icon->set_css("display", "inline");
                }
            }
            else
            {
                if (in_array($action, $this->displayed) || $first)
                    $icon->set_css("display", "none");
            }
        }
        
        $this->save_data("displayed", $displayed);
    }
    
    function click_action($action)
    {
        $desc = $this->s["actions"][$action];
        $this->debug("clicked action $action on objref {$this->objref}");
        call_user_func($desc["handler"], $action, $this->objref);
    }
    
    function update_data($data)
    {
        $this->data = $data;
        if (isset($this->s["action_filter"])) $this->update();
    }
}

class ObjectListCell extends AppElement
{
    function __construct($app, $id, $label)
    {
        $this->label = $label;
        parent::__construct($app, $id);
    }
    
    function render()
    {
        $this->set_content(htmlspecialchars($this->label));
        $this->set_css("text-overflow", "ellipsis");
    }
}

class ObjectListHeader extends AppElement
{
    function __construct($app, $id, $settings)
    {
        $this->s = $settings;
        parent::__construct($app, $id);
    }
    
    function init()
    {
        $this->cells = array();
        
        foreach ($this->s["fields"] as $f => $fs)
        {
            $fid = str_replace("-", "", $f);
            $this->cells[$f] = array(
                new ObjectListCell($this->app, "{$this->id}_$fid", $fs["title"]),
                $fs["weight"]
            );
        }
    }

    function render()
    {
        foreach ($this->cells as $f => $c)
        {
            $this->add_child($c[0]);
            $c[0]->set_class("list_header");
        }
            
        $this->set_css("position", "relative");
        $this->set_css("height", "1.2em");
        $this->column_layout($this->cells, "hidden");
    }
}

class ObjectListCloser extends AppElement
{
    function render() {}
}

class ObjectListItem extends AppElement
{
    function __construct($app, $id, $data, $objref, $settings)
    {
        $this->data = $data;
        $this->objref = $objref;
        $this->s = $settings;
        parent::__construct($app, $id);
    }
    
    function init()
    {
        $this->cells = array();
        $this->actioncell = FALSE;
        
        foreach ($this->s["fields"] as $f => $fs)
        {
            $value = $this->data[$f];
            if (isset($fs["xform"]))
                $value = call_user_func($fs["xform"], $value);
        
            if ($this->s["main_field"] == $f)
                $this->label = $value;
                
            $fid = str_replace("-", "", $f);
            
            if ($f == "0act")
            {
                $cell = new ObjectListActionCell($this->app, "{$this->id}_$fid", $this->s, $this->data);
                $this->actioncell = $cell;
            }
            elseif ($fs["display"] == "progress")
                $cell = new ProgressBarElement($this->app, "{$this->id}_$fid");
            else
                $cell = new ObjectListCell($this->app, "{$this->id}_$fid", $value);
                
            $this->cells[$f] = array($cell, $fs["weight"]);
        }
    }

    function render()
    {
        foreach ($this->cells as $f => $c)
        {
            $this->add_child($c[0]);
            
            $fs = $this->s["fields"][$f];
            if (isset($fs["style"]))
            {
                foreach ($fs["style"] as $prop => $val)
                    $c[0]->set_css($prop, $val);
            }
            
            switch ($fs["display"])
            {
            case "progress":
                $c[0]->set_percent(floatval($this->data[$f]));
                break;
            }
        }
        
        $this->set_class("list_item");
            
        $this->set_css("position", "relative");
        $this->set_css("height", "1.3em");
        $this->set_dom("title", $this->data[$this->s["main_field"]]);
        $this->column_layout($this->cells, "hidden");   
        $this->make_draggable($this->objref, $this->data[$this->s["main_field"]]);
            
        if (isset($this->s["item_drop_handler"]))
        {
            $dt = $this->s["item_drop_handler"];
            $this->make_drag_target($dt["handler"], $dt["method"]);
        }
    }
    
    private function set_cell_value($field, $value)
    {    
        $fs = $this->s["fields"][$field];
        if (isset($fs["xform"]))
            $value = call_user_func($fs["xform"], $value);
    
        if ($this->s["main_field"] == $field)
            $this->label = $value;
                
        switch ($fs["display"])
        {
        case "progress":
            $this->cells[$field][0]->set_percent(floatval($value));
            break;
        default:
            $this->cells[$field][0]->set_content($value);
            break;
        }
    }
    
    function update_data($updated)
    {
        if (count($updated))
        {
            foreach ($updated as $f => $v)
            {
                $this->data[$f] = $v;
                if (isset($this->s["fields"][$f])) $this->set_cell_value($f, $v);
            }
            
            if ($this->actioncell) $this->actioncell->update_data($this->data);
        }
    }
}

class RefreshObjectListBody extends ObjectListBody
{
    protected function fetch($expr)
    {
        $objs = $this->obj->match_objects($this->s["apps"], $expr, $this->s["lod"], $this->s["otype"]);
        
        $removed_ids = array_keys($this->children);
        $positions = array();
        foreach ($objs as $o)
        {
            $objref = $o->objref;
            $props = $o->props;
            $id = $props[$this->s["unique_field"]];
            $positions[] = $id;
            
            if (isset($this->children[$id]))
            {
                /* Existing item : update properties */
                $oldprops = $this->children_data[$id];
                $newprops = array();
                foreach ($props as $p => $v)
                    if ($p != "0ref" && $v != $oldprops[$p]) $newprops[$p] = $v;
                $this->children[$id]->update_data($newprops);
                
                unset($removed_ids[array_search($id, $removed_ids)]);
            }
            else
            {
                /* New item : create new child */
                $child = new ObjectListItem($this->app, "{$this->id}_I$id", $props, $objref, $this->s);
                $this->add_item($child, $id, $props);
            }
        }
        
        /* Removed items */
        foreach ($removed_ids as $id)
        {
            $this->remove_item($id);
        }
        
        /* Move to new positions */
        $this->change_item_positions($positions);
        
        $this->schedule_update($this->s["refresh"]);
    }
}

class FixedObjectListBody extends ObjectListBody
{
    protected function fetch($expr)
    {
        if ($this->first && $this->s["delay_fetch"])
        {
            $this->schedule_update(0);
        }
        else
        {
            $offset = $this->count;
            $limit = isset($this->s["limit"]) ? $this->s["limit"] : -1;
            $objs = $this->obj->match_objects($this->s["apps"], $expr, $this->s["lod"], $this->s["otype"], $offset, $limit);
            
            foreach ($objs as $o)
            {
                $objref = $o->objref;
                $props = $o->props;
                $id = $props[$this->s["unique_field"]];
                
                $child = new ObjectListItem($this->app, "{$this->id}_I$id", $props, $objref, $this->s);
                $this->add_item($child, $id, $props);
            }
        
            /* Schedule new update until there are no more objects */
            if (isset($this->s["limit"]) && $this->s["limit"] > 0 && count($objs) == $this->s["limit"])
                $this->schedule_update(0);
        }
    }
    
    function render()
    {
        $this->first = TRUE;
        parent::render();
    }
}

abstract class ObjectListBody extends AppElement
{
    public $count = 0;
    
    protected $children_data = array();
    protected $children = array();
    private $selected_id = -1;
    
    function __construct($app, $id, $settings)
    {
        $this->s = $settings;
        parent::__construct($app, $id);
    }
    
    function init()
    {
        /* Load previously created children */
        $this->children_data = $this->load_data("children", array());
        foreach ($this->children_data as $id => $data)
        {
            $this->children[$id] = new ObjectListItem($this->app, "{$this->id}_I$id", $data, $data["0ref"], $this->s);
        }
        
        $this->selected_id = intval($this->load_data("selected_id", -1));
        $this->count = count($this->children);
        
        $this->closer = new ObjectListCloser($this->app, "{$this->id}_END");
    }
    
    function take_scroll_container($scroll)
    {
        $this->scroll = $scroll;
    }
    
    function item_event($arg)
    {
        list($ev, $id) = explode(" ", $arg);
        if (isset($this->s["item_events"][$ev]))
        {
            call_user_func($this->s["item_events"][$ev], $this->children[$id]);
        }
    }
    
    function render()
    {
        $this->count = 0;
        $this->children = array();
        $this->children_data = array();
        
        $this->set_content("");
        $this->add_child($this->closer);
        $this->update();
        
        if (isset($this->s["drop_handler"]))
        {
            $dt = $this->s["drop_handler"];
            $this->make_drag_target($dt["handler"], $dt["method"]);
        }
    }
    
    function update()
    {
        $expr = $this->get_filter_expr();
        
        if ($expr) $this->fetch($expr);
        else $this->set_content("no filter...");
        
        $this->save_data("children", $this->children_data);
        
        /* Move closer to the bottom */
        $this->remove_child($this->closer);
        $this->add_child($this->closer);
        
        /* Refresh scrollbar */
        $this->scroll->refresh_scrollbar();
    }
    
    /* Fetch objects, must call $this->add_item($child_element, $item_id) to add items */
    abstract protected function fetch($expr);
    
    function set_filter($filter)
    {
        if (isset($this->s["filter"]) && $filter != $this->load_data("filter", FALSE))
        {
            $this->save_data("filter", $filter);
            $this->render();
        }
    }
    
    function set_link($id)
    {
        if (isset($this->s["link"]))
        {
            $data = array();
            foreach ($this->s["link_fields"] as $field)
                $data[$field] = $this->children_data[$id][$field];
            $this->s["link"]->set_filter($data);
        }
        
        if ($this->children[$this->selected_id])
            $this->children[$this->selected_id]->unset_class("selected");
        $this->children[$id]->set_class("selected");
        $this->selected_id = $id;
        $this->save_data("selected_id", $id);
    }
    
    private function get_filter_expr()
    {
        if (isset($this->s["filter"]))
        {
            $filter = $this->load_data("filter", FALSE);
            if (!$filter) return FALSE;
            
            $expr = FALSE;
            foreach ($this->s["filter"] as $field)
            {
                if ($expr) $expr = _and($expr, _c($field, '==', $filter[$field]));
                else $expr = _c($field, '==', $filter[$field]);
            }
            
            return $expr->is_expr() ? $expr : _e($expr);
        }
        
        return _e(FALSE);
    }
    
    protected function add_item($child, $id, $data)
    {
        $this->children[$id] = $child;
        $this->children_data[$id] = $data;
        $this->count++;
        
        $this->add_child($child);
        
        if ($id == $this->selected_id) $child->set_class("selected");
        
        if (isset($this->s["link"]))
        {
            $child->set_handler("onclick", $this, "set_link", $id);
        }
        
        if (isset($this->s["item_events"]))
        {
            foreach ($this->s["item_events"] as $ev => $callback)
            {
                $child->set_handler($ev, $this, "item_event", "$ev $id");
            }
        }
    }
    
    protected function remove_item($id)
    {
        if (isset($this->children[$id]))
        {
            $this->remove_child($this->children[$id]);
            
            unset($this->children[$id]);
            unset($this->children_data[$id]);
            $this->count--;
        }
    }
    
    /* Change item positions to match $positions, which is array( position => item id) */
    protected function change_item_positions($positions)
    {
        $new_children = array();
        $new_children_data = array();
    
        /* Get current item positions */
        $cur_positions = array();
        foreach (array_keys($this->children) as $id) $cur_positions[$id] = count($cur_positions);
        
        /* Move items to new positions */
        foreach ($positions as $pos => $id)
        {
            $new_children[$id] = $this->children[$id];
            $new_children_data[$id] = $this->children_data[$id];
            
            if ($cur_positions[$id] > $pos)
            {
                $oldpos = $cur_positions[$id];
                
                $swapid = array_search($pos, $cur_positions);
                $this->children[$id]->swap_with($this->children[$swapid]);
                
                $cur_positions[$id] = $pos;
                $cur_positions[$swapid] = $oldpos;
            }
        }
        
        /* Save children in new order */
        $this->children = $new_children;
        $this->children_data = $new_children_data;
    }
}

class ObjectListTitle extends AppElement
{
    function render()
    {
        $this->set_class("list_title");
    }
}

/* Auto-refreshed ObjectList element.
    Can be used for lists with objects that change often. When the list is created, all objects
    are fetched, and updates are then incremental. The "refresh" $settings key specifies the
    refresh rate in milliseconds.
*/
class RefreshObjectList extends ObjectList
{
    function get_list_body()
    {
        return new RefreshObjectListBody($this->app, "{$this->id}_BDY", $this->s);
    }
}

/* Fixed ObjectList element
    Can be used for lists of objects that don't change very often. Adds an optional "limit" key
    to $settings: when specified, objects will be fetched in chunks of size "limit", which
    prevents huge loading times for lists with a lot of objects. Also adds an optional
    "delay_fetch" settings key; when present and set to 1, objects will not be fetched on
    initial rendering but on next update.
    
    Also implements an additional reload() method to reload the entire list.
*/
class FixedObjectList extends ObjectList
{
    function get_list_body()
    {
        return new FixedObjectListBody($this->app, "{$this->id}_BDY", $this->s);
    }
    
    function reload()
    {
        $this->lst->render();
    }
}

/* ObjectList element
    Generic object list element with many features :
    - multi-column display with optional column title and CSS
    - DOM events on objects
    - draggable objects
    - links between lists (i.e. what B displays depends on what is selected in A)
    - objects and the list itself can be made drop targets
    
    The constructor $settings parameter is an array with the following keys (keys marked with *
    are optional):
    
      "title" => displayed list title
      "apps"  => list of source apps for objects (1)
      "otype" => list of object types to display (1)
      "lod"   => LOD to fetch objects with
      
      "fields" => array(
          "fieldname" => array(
              "title"  => displayed field title (2)
              "weight" => field column weight
    *         "xform"  => callback to transform the field value before displaying
    *         "style" => array(
                  "style-property" => "style-value"
                  ...
              )
          ...
          )
      )
        
      "unique_field" => name of a field used to identify each object (should be a valid DOM id)
      "main_field" => name of a field used to describe objects when dragging
      
    * "item_events" => array(
          "oneventname" => callback (will be passed the AppElement on which the event happened)
          ...
      )
      
    * "filter" => array("fieldname", ...) fields used for filtering
      
    * "link" => linked ObjectList
    * "link-fields" => array("fieldname", ...) fields passed to linked ObjectList (3)
    
    * "item_drop_handler" => array(
          "handler" => object drop handler element
          "method" => object drop handler method
      )
      Enable dropping objects on list items. The callback will be passed the item AppElement and
      the dropped object objref
      
    * "drop_handler" => array(
          "handler" => object drop handler element
          "method" => object drop handler method
      )
      Same as "item_drop_handler", except it is called when an object is dropped on the list
      itself, and the ObjectList element is passed as first argument
      
    * "actions" => array(
          "action-name" => array(
              "title" => readable action description
              "handler" => callback to launch the action, receives (action-name, objref) as parameters
              "icon" => action icon name
          )
          ...
      )
      Enable action buttons for each item. Actions will be displayed in the "0act" column.
      
    * "action_filter" => callback receving (action-name, objref, object-properties), must return
                         a boolean telling if the action must be displayed or not.
    
    (1) can also be a comma-separated list
    (2) field titles will only be displayed if the "main_field" has a "title" attribute
    (3) "link-fields" is mandatory when "link" is specified; the specified fields values are
        matched against the linked ObjectList "filter" fields, in the same order
        
    The following "special" fields are available for all objects:
        "0app": name of owner application
        "0ref": complete object reference
        "0act": column holding action buttons
*/
abstract class ObjectList extends AppElement
{
    public $title;
    public $scroll;

    function __construct($app, $id, $settings)
    {
        $this->s = $settings;
        parent::__construct($app, $id);
    }
    
    abstract function get_list_body();
    
    function init()
    {
        $this->scroll = new ScrollContainerElement($this->app, "{$this->id}_S");
        
        $this->lst = $this->get_list_body();
        $this->lst->take_scroll_container($this->scroll);
        $this->title = new ObjectListTitle($this->app, "{$this->id}_TIT");
        
        if (isset($this->s["fields"][$this->s["main_field"]]["title"]))
            $this->header = new ObjectListHeader($this->app, "{$this->id}_HDR", $this->s);
        else $this->header = FALSE;
    }
    
    function set_filter($filter)
    {
        $this->lst->set_filter($filter);
    }
    
    function render()
    {
        $hheight = "1.6em";
        $this->add_child($this->title);
        $this->title->set_css("height", "1.2em");
        $this->title->set_content($this->s["title"]);
        
        if ($this->header)
        {
            $hheight = "2.8em";
            $this->add_child($this->header);
        }
        
        $this->add_child($this->scroll);
        $this->scroll->set_css("position", "absolute");
        $this->scroll->set_css("top", $hheight);
        $this->scroll->set_css("left", "0");
        $this->scroll->set_css("right", "0");
        $this->scroll->set_css("bottom", "0");
        $this->scroll->add_child($this->lst);
        
        $this->lst->set_css("width", "100%");
    }
}

?>
