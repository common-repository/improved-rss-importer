<?php
/*  Copyright 2011 Theodore Witkmap  (email : theodore.witkamp@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Parse an RSS feed
class TagParser
{
	// XML Parser
	var $xml_parser;
	// stack of XML tags
	var $tag_stack = array();
	function __construct()
	{
		// Configure the XML Parser to call methods in this class
		$this->xml_parser = xml_parser_create();
		xml_set_element_handler($this->xml_parser,
		(array($this,"start_element")),
		(array($this,"end_element")));
		xml_set_character_data_handler($this->xml_parser,
		(array($this,"cdata")));

	}
	public function gen_tag($name){
		// Overlaod this!
		return new Tag($name);
	}
	public function start_element($parser,$name,$attr)
	{
		$tag = $this->gen_tag($name);
		$tag->start($attr);
		array_push($this->tag_stack,$tag);
	}

	public function cdata($parser,$data)
	{
		// Character Data
		$tag = end($this->tag_stack);
		$tag->append_child($data);
	}

	public function end_element($parser,$name)
	{
		$tag = array_pop($this->tag_stack);
		// check the see if the tag names match

		if(strcasecmp($tag->get_name() ,$name) != 0)
		{
			echo $tag->get_name(). " != " . $name;
			assert(false);
		}

		if(!empty($this->tag_stack))
		{
			// Add it as the parents child
			end($this->tag_stack)->append_child($tag);
		}
		$tag->end();
	}

	function read($fp)
	{
		while($data = fread($fp,4096))
		{
			if(!xml_parse($this->xml_parser,$data,feof($fp)))
			{
				die(sprintf("XML Error: %s at line %d",
				xml_error_string(xml_get_error_code($this->xml_parser)),
				xml_get_current_line_number($this->xml_parser)));
			}
		}

	}
}


class Tag
{
	var $name;
	public function __construct($name)
	{
		$this->name = $name;
	}
	public function get_name()
	{
		return $this->name;
	}
	public function get_value(){}
	public function start($attr){}
	// child may be text or tag
	public function append_child($child){}
	public function end(){}
}

class TextTag extends Tag
{
	var $text = "";
	public function append_child($child)
	{
		if(is_string($child))
		{
			$this->text = $this->text . $child;
		}
	}
	public function get_value()
	{
		return $this->text;

	}
}
class ItemTag extends Tag
{
	var $elements = array();
	public function get_tag($tag_name)
	{
		return $this->elements[$tag_name];
	}
	// title
	// description
	// author
	// category
	// enclosure
	// guid
	// pubdate
	public function __construct()
	{
		$this->name = "ITEM";
	}

	public function append_child($child)
	{
		if(!is_string($child))
		{
			if(!$this->elements[$child->get_name()])
			{
				// Initialize the array.
				$this->elements[$child->get_name()] = array();
			}
			array_push($this->elements[$child->get_name()],$child->get_value());
		}
	}

	public function end()
	{
		// End of the post tag
		// post the Post
		$post_title   = $this->get_tag('TITLE');
		// Allow only one title
		$post_title   = trim($post_title[0]);
		// TODO: The description might not be the content
		$post_content = $this->get_tag('DESCRIPTION');
		$post_content = $post_content[0];
		// TODO: Can we have more than one author?
		$post_author  = trim($this->get_tag('AUTHOR'));
		$categories   = $this->get_tag('CATEGORY');
		$enclosures   = $this->get_tag('ENCLOSURE');
		$guid         = $this->get_tag('GUID');
		// There can be one one guid
		$guid         = trim($guid[0]);
		// Handle teh Date format
		$post_date_gmt = $this->get_tag('PUBDATE');
		$post_date_gmt = strtotime($post_date_gmt[0]);
		$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
		$post_date = get_date_from_gmt( $post_date_gmt );

		echo "Title:" . $post_title . "<br/>";
		echo "Author: ".$post_author."<br/>";
		echo "Category: " . $categories . "<br/>";
		echo "Enclosure: " . $enclosures[0] . "<br/>";
		echo "GUID: " . $guid . "<br/>";
		echo "PUBDATE: ". $post_date_gmt . "<br/>";

		// TODO: make the author user changable
		// Set the author to the first author on the system
		$post_author = wp_get_current_user()->ID;
		// TODO: Add options for the user to change the default state of the
		//       imported post
		$post_status = 'publish';

		$post = compact('post_author',
		  'post_date', 
		  'post_date_gmt', 
		  'post_content', 
		  'post_title', 
		  'post_status', 
		  'guid', 
		  'categories');

		// Check to see is the post already exist
		if ($post_id = post_exists($post_title, $post_content, $post_date)) {
			_e('Post already imported');
		} else {
			$post_id = wp_insert_post($post);
			if ( is_wp_error( $post_id ) )
			return $post_id;
			if (!$post_id) {
				_e('Couldn&#8217;t get post ID');
				return;
			}
			
      // add Categories
			if (count($categories) > 0) {
				wp_create_categories($categories, $post_id);
				_e('created cat<br/>');
			}
			// Add Enclosures
			if(count($enclosures) > 0) {
				foreach($enclosures as $enc) {
					// Encode for WP storage.
					add_post_meta($post_id,"enclosure",$enc->__toString());
					_e('adding enclosure<br/>');
				}
			}
			_e('Done !');
		}

	}
}

class Enclosure{
	var $url = "";
	var $length;
	var $type;
	public function __toString(){
		return $this->url . "\n" . $this->length . "\n" . $this->type;
	}
}
class EnclosureTag extends Tag
{
	var $enc;
	public function __construct()
	{
		$this->name = "ENCLOSURE";
	}
	public function start($attr)
	{
		$this->enc  = new Enclosure();
		$this->enc->url     = $attr['URL'];
		$this->enc->length  = $attr['LENGTH'];
		$thsi->enc->type    = $attr['TYPE'];
	}

	public function get_value(){
	 return $this->enc;
	}
}


// RSS parser
class RSSParser extends TagParser
{
	var $map  = array();
	private function add_tag($tag)
	{
		$this->map;
		$this->map[$tag->get_name()] = $tag;
	}

	function __construct(){
		parent::__construct();
		// title
		// description
		// content:encoded
		// author
		// category
		// enclosure
		// guid
		// pubdate
		$this->add_tag(new ItemTag());
		$this->add_tag(new TextTag("TITLE"));
		$this->add_tag(new TextTag("DESCRIPTION"));
		$this->add_tag(new TextTag("AUTHOR"));
		$this->add_tag(new TextTag("CATEGORY"));
		$this->add_tag(new EnclosureTag());
		$this->add_tag(new TextTag("GUID"));
		$this->add_tag(new TextTag("PUBDATE"));
			
	}
	function gen_tag($name){
		$this->map;
		$v = $this->map[$name];
		if($v)
		{
			// Clone the prototype
			return clone $v;
		}
		// generate tag data strutures
		return new Tag($name);
	}

}

?>