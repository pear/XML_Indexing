<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * XML_Indexing's core index building routines
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   XML
 * @package    XML_Indexing
 * @copyright  2004 Samalyse SARL corporation
 * @author     Olivier Guilyardi <olivier@samalyse.com>
 * @license    http://www.php.net/license/3_0.txt  PHP License
 * @version    CVS: $Id$
 * @link       http://pear.php.net
 * @since      File available since Release 0.1
 */
    
/**
 * Indexes building core code
 *
 * @copyright  2004 Samalyse SARL corporation
 * @author  Olivier Guilyardi <olivier@samalyse.com>
 * @license    http://www.php.net/license/3_0.txt  PHP License
 * @version    Release: @package_version@
 * @link       http://pear.php.net
 * @since      Class available since Release 0.1
 */
class XML_Indexing_Builder
{
    /**
     * Expat parser
     * @var resource
     * @access private
     */
    var $_parser;

    /**
     * What's currently being parsed by callback functions
     * @var string
     * @access private
     */
    var $_cur_xpath = '';

    /**
     * The XPath root we're working on
     * @var string
     * @access private
     */
    var $_xroot;

    /**
     * The atributes of the last matching element
     * @var array
     * @access private
     */
    var $_attribs = array();

    /**
     * The byte index of the last matching element
     * @var int
     * @access private
     */
    var $_byteIndex = 0;

    /**
     * Trigger to properly handle regions' end offsets
     * @var int
     * @access private
     */
    var $_endingTrigger = 0;
    
    /**
     * Matched XML data portions
     * @var array
     * @access private
     */
    var $_regions = array();

    /**
     * Size of chunks to feed the Expat parser with
     * @var int
     * @access private
     */
    var $_bufferSize = 1048576;
    
    /**
     * Set to true if PHP5's Expat bug is detected
     * @var bool
     * @access private
     */
    var $_expatBugWorkaround = false;
   
    /**
     * XML Data as a raw string or filename. 
     * @var string
     * @access private
     */
    var $_xmlSource = null;
  
    /**
     * XPath root substring used as scope pattern
     * 
     * @var string
     * @access private
     */
    var $_scopePattern = null;

    /**
     * Parsed and extracted namespaces declarations
     *
     * @var array
     * @access private
     */
    var $_nameSpaces = array();
   
    /**
     * Set to true if the document got parsed
     * 
     * @var bool
     * @access private
     */
    var $_isParsed = false;
    
    /**
     * Constructor
     * 
     * @param string $xml The filename or xml string to build an index against.
     *                    If it is a string, it has to start with '<?xml' ; if 
     *                    that is not the case, it will be recognized as a
     *                    filename.
     * @param string $xroot XPath root 
     * @access public
     */
    function XML_Indexing_Builder ($xmlSource, $xroot)
    {
        $this->_xroot = $xroot;
        $this->_xmlSource = $xmlSource;
        $cut = explode ('/',$xroot);
        if (($ii = count($cut)) > 2) {
            $cut = array_slice ($cut, 0, $ii - 2);
            $this->_scopePattern = join ('/', $cut);
        } 
    }


    /**
     * Perform xml data parsing and index building
     *
     * @return mixed True or a PEAR_Error object
     * @access private
     */
    function _parse()
    {
        if ($this->_xroot == '##EXPATBUGCHECKING##') {
            $this->_xroot = '/test/a';
        } elseif (substr(phpversion(),0,1) == 5) {
            $bug = $this->_isExpatBuggy();
            if (PEAR::isError($bug)) {
                return new PEAR_Error ("Expat parsing error");
            } else {
                $this->_expatBugWorkaround = $bug;
            }
        }
        $this->_parser = xml_parser_create();
        xml_parser_set_option($this->_parser, XML_OPTION_CASE_FOLDING, false);
        xml_set_object ($this->_parser, $this);
        xml_set_element_handler ($this->_parser, '_handleStartElement', 
                                                   '_handleEndElement') 
            or exit("Can attach handlers");
        // Due to some bug in PHP5 (and possibly PHP4) the following's not used 
        // currently. A workaround is implemented.
        // xml_set_start_namespace_decl_handler ($this->_parser, 
        //                                       '_handleNameSpace');
        xml_set_default_handler ($this->_parser, '_handleDefault');                                                
        
        $isFilename = (substr($this->_xmlSource,0,5) != '<?xml'); 
        
        if ($this->_expatBugWorkaround) {
            if ($isFilename) {
                $this->_xmlSource = file_get_contents ($this->_xmlSource);
                $isFilename = false;
            }
            $ii = strlen ($this->_xmlSource) - 1; 
            $alpha = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            for ($i=0; $i < $ii and 
                 ($this->_xmlSource[$i] != '<' 
                     or strspn ($this->_xmlSource[$i+1], $alpha) == 0); 
                 $i++) {
                $this->_xmlSource[$i] = ' ';
            }
        }
        
        if (!$isFilename) {
            if (!xml_parse ($this->_parser, $this->_xmlSource)) {
                return new PEAR_Error ("Expat parsing error");
            }
        } else {
           if ($fp = fopen ($this->_xmlSource,'r')) {
                while (!feof($fp)) {
                    if (!xml_parse ($this->_parser, 
                                    fread ($fp, $this->_bufferSize),false)) {
                        fclose ($fp);
                        return new PEAR_Error ("Expat parsing error");
                    }
                }
                if (!xml_parse ($this->_parser, '', true)) {
                    fclose($fp);
                    return new PEAR_Error ("Expat parsing error");
                }
                fclose ($fp);
            } 
        }

        return true;
    }
   
    /**
     * Check for PHP5's Expat bug
     * 
     * For more info check the following PHP bug :
     * http://bugs.php.net/bug.php?id=30257
     * 
     * @return mixed True if the known PHP5 bug is found, false if no bug's 
     *               found, or a PEAR_Error, if a unknown behaviour is 
     *               detected.
     * @access private
     */
    function _isExpatBuggy ()
    {
        $xml = 
            '<?xml version="1.0" encoding="ISO-8859-1"?>       ' .
            '<!DOCTYPE test [                                  ' .
            '<!ELEMENT test (a*)>                              ' .
            '<!ELEMENT a       (#PCDATA)>                      ' .
            ']>                                                ' .
            '<test>                                            ' .
            '  <a> 1 </a>                                      ' .
            '  <a> 1 </a>                                      ' .
            '  <a> 1 </a>                                      ' .
            '  <a> 4 </a>                                      ' .
            '</test>                                           ';

        require_once 'XML/Indexing/Builder/Numeric.php';    
        $builder = new XML_Indexing_Builder_Numeric($xml,'##EXPATBUGCHECKING##');
        $index = $builder->getIndex();
        $expected = array (
            1 => array(array(302,10)),
            2 => array(array(352,10)),
            3 => array(array(402,10)),
            4 => array(array(452,10)));
        $knownPhp5Bug = array (
            1 => array(array(263,50)),
            2 => array(array(313,50)),
            3 => array(array(363,50)),
            4 => array(array(413,53)));

        if ($index == $expected) {
            $ret = false;
        } elseif ($index == $knownPhp5Bug) {
            $ret = true;
        } else {
            $ret =  new PEAR_Error ('Unhandled PHP5 bug - Can\'t do anything.');
            echo "Alert: Unhandled PHP5 Expat bug ! (debug data: " . 
                 serialize ($index) . ")\n";
        }

        return $ret;

    }
    
    /**
     * Start elements Expat's callback
     *
     * @access private
     */
    function _handleStartElement ($parser, $name, $attribs) 
    {
       if ($this->_endingTrigger) { 
            $bi = $this->_getByteIndex();
            $len = $bi - $this->_byteIndex;
            $this->_handleRegion ($this->_byteIndex, $len, $this->_attribs);
            $this->_endingTrigger = 0;
        }
        if ($this->_cur_xpath == $this->_scopePattern) {
            $this->_enterScope();
        }
        $this->_cur_xpath .= "/$name";
        if ($this->_cur_xpath == $this->_xroot) {
            $this->_attribs = $attribs;
            $bi = $this->_getByteIndex();
            $this->_byteIndex = $bi;
        }
        // Heavy workaround for xml_set_start_namespace_decl_handler() bug :
        foreach ($attribs as $name => $value) {
            if (substr ($name, 0, 6) == 'xmlns:') {
                list ($junk, $prefix) = explode(':',$name);
                $this->_nameSpaces[$prefix] = $value;
            }
        }
    }
   
    /**
     * End elements Expat's callback
     *
     * @access private
     */
    function _handleEndElement ($parser, $name) 
    {
       if ($this->_endingTrigger) { 
            $bi = $this->_getByteIndex();
            $len = $bi - $this->_byteIndex;
            $this->_handleRegion ($this->_byteIndex, $len, $this->_attribs);
            $this->_endingTrigger = 0;
        }
        if ($this->_cur_xpath == $this->_xroot) {
            $this->_endingTrigger = 1;
        }
        $this->_cur_xpath = preg_replace("/\/$name$/", '', $this->_cur_xpath);
        if ($this->_cur_xpath == $this->_scopePattern) {
            $this->_exitScope();
        }
    }

    /**
     * Namespace declaration Expat's callback
     *
     * NOTE: Due to some PHP5 (and possibly PHP4) bug, this method is not
     * used currently. A workaround is implemented.
     * 
     * @access private
     */
    
    function _handleNameSpace ($parser, $prefix, $uri) 
    {
        $this->_nameSpaces[$prefix] = $uri;
    }
     
    /**
     * Default Expat's callback
     *
     * @access private
     */
    function _handleDefault ($parser, $data)
    {
       if ($this->_endingTrigger) { 
            $bi = $this->_getByteIndex();
            $len = $bi - $this->_byteIndex;
            $this->_handleRegion ($this->_byteIndex, $len, $this->_attribs);
            $this->_endingTrigger = 0;
        }
    }
    
    /**
     * Prototype for region handling
     * 
     * This has to be overloaded by child classes to actually do anything real.
     *
     * @param int $offset Byte offset of the matched region
     * @param int $length Length in bytes of the matched region
     * @param array $attribs Attributes of the tag enclosing the region
     * @access protected
     * @return void
     */
    function _handleRegion($offset, $length, $attribs)
    {
    }

    /**
     * Prototype for entering node scope
     * 
     * This may be overloaded by child classes.
     *
     * @access protected
     * @return void
     */
    function _enterScope()
    {
    }
    
    /**
     * Prototype for exiting node scope
     * 
     * This may be overloaded by child classes.
     *
     * @access protected
     * @return void
     */
    function _exitScope()
    {
    }
    
    /**
     * Retrieve Expat's current byte index
     *
     * @return int Zero-based byte index
     * @access private
     */
    function _getByteIndex()
    {
        $bi = xml_get_current_byte_index ($this->_parser);
        if ($this->_expatBugWorkaround) {
            for ($i = $bi-1; $this->_xmlSource[$i] != '<' and $i > 0; $i--);
            $bi = $i;
        }
        return $bi;
    }
    
    /**
     * Return the built index
     *
     * @return array All regions with offset and length
     * @access public
     */
    function getIndex ()
    {
        if (!$this->_isParsed) {
            $this->_parse();
            $this->_isParsed = true;
        }
        return $this->_regions;
    }

    /**
     * Return extracted namespaces declarations
     *
     * @return array Associative array of the form: array ('prefix' => 'uri', ...)
     * @access public
     */
    function getNamespaces ()
    {
        if (!$this->_isParsed) {
            $this->_parse();
            $this->_isParsed = true;
        }
        return $this->_nameSpaces;
    }
}
    
?>
