<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * XML_Indexing's attribute-based indexes builder
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
    

require_once 'XML/Indexing/Builder.php';
    
/**
 * Attribute-based indexes builder
 *
 * @copyright  2004 Samalyse SARL corporation
 * @author  Olivier Guilyardi <olivier@samalyse.com>
 * @license    http://www.php.net/license/3_0.txt  PHP License
 * @version    Release: @package_version@
 * @link       http://pear.php.net
 * @since      Class available since Release 0.1
 */
class XML_Indexing_Builder_Attribute extends XML_Indexing_Builder
{
    /**
     * Attribute being analysed
     * @var string 
     * @access private
     */
    var $_attributeName;

    /**
     * Constructor
     * 
     * @param string $filename The filename to build an index against
     * @param string $xroot XPath root 
     * @param string $attr Name of the attribute to analyse
     * @access public
     */
    function XML_Indexing_Builder_Attribute ($filename, $xroot, $attr)
    {
        $this->_attributeName = $attr;
        $this->XML_Indexing_Builder ($filename, $xroot);
    }

    /**
     * Handle a matched region
     *
     * @param int $offset Byte offset of the matched region
     * @param int $length Length in bytes of the matched region
     * @param array $attribs Attributes of the tag enclosing the region
     * @access protected
     * @return void
     */
    function _handleRegion($offset, $length, $attribs)
    {
        if (isset ($attribs[$this->_attributeName])) {
            $this->_regions[$attribs[$this->_attributeName]][] 
                = array ($offset, $length);
        }
    }
}
    
?>
