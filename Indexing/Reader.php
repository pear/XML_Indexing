<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * XML_Indexing reader class
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

require_once 'File.php';
 
/**
 * Transparent XML Indexing Reader
 *
 * This class allows to work on big XML files without madly increasing access time.
 * For this purpose, it creates an index, which contains informations to rapidly
 * seek through a given XML file to retrieve a specific portion of it.
 * 
 * The indexing process is based on XPath expressions. Not all of the XPath language,
 * but an appropriate subset for what a big XML files is expected to contain.
 *
 * Currently, this class works transparently, creating specific indexes upon specific
 * requests.
 *
 * For example, when initially looking for /foo/bar[232], all instances from
 * /foo/bar[1] to /foo/bar[n] will get indexed (so the first run is slow). 
 * Subsequent calls with such expressions as /foo/bar[232], /foo/bar[100], 
 * /foo/bar[25], etc... will then all make use of the created index (fast).
 * 
 * In addition to numerical indexes, attribute values indexing is currently
 * supported as well. That is, expressions as /foo/bar[@id='someValue']. Similarly
 * to the numerical indexing process, looking for a such expression will index
 * all values of the 'id' attribute for the given XPath root (/foo/bar here).
 *
 * Using this class is pretty straightforward :
 *
 * <code>
 * $reader = new XML_Indexing_Reader ('test.xml');
 * $reader->find('/foo/bar[232]'); // Or any other XPath expression
 * $xmlStrings = $reader->fetchStrings();
 * 
 * echo "Extracted XML data : "
 * foreach ($xmlStrings as $n => $str) {
 *     echo "######## Match $n ######### \n";
 *     echo "$str\n\n";
 * }
 * </code>
 *
 * Namespaces extraction is supported. These namespaces declarations are stored
 * in the index files. You can retrieve them with :
 *
 * <code>
 * $reader->find(...); // Needs to be call prior to getNamespaces()
 * $nsList = $reader->getNamespaces();
 * foreach ($nsList as $prefix => $uri) {
 *     echo "$prefix => $uri";
 * }
 * </code>
 * 
 * The index storage strategy can be customized by modifying the default dsn
 * value. Currently, only local file containers are supported.
 * <code>
 *
 * // The following will store indexes in /tmp, using file names with an .xi 
 * // prefix. That is the default.
 * $options['dsn'] = 'file:///tmp/%s.xi';
 * $indexer = new XML_Indexing_Reader ('test.xml', $options);
 *
 * // You can specify your own path as long as you include the %s expression :
 * $options['dsn'] = 'file:///var/cache/xi/%s.xi'
 * $indexer = new XML_Indexing_Reader ('test.xml', $options);
 * </code>
 *
 * See the constructor documentation for more information on options.
 *
 * @copyright  2004 Samalyse SARL corporation
 * @author     Olivier Guilyardi <olivier@samalyse.com>
 * @license    http://www.php.net/license/3_0.txt  PHP License
 * @version    Release: @package_version@
 * @link       http://pear.php.net
 * @since      Class available since Release 0.1
 */
class XML_Indexing_Reader {
   
    /**
     * Global options
     * @var array
     * @access private
     */
    var $_options = array (
            'dsn'       => null, // dynamically generated default value 
            'gz_level'  => 0,
            'profiling' => false,
        );
  
    /**
     * Currently used index
     * @var array
     * @access private
     */
    var $_index = array();

    /**
     * XML file name being parsed
     * @var string
     * @access private
     */
    var $_xmlFilename = null;
  
    /**
     * XML file resource
     * @var resource
     * @access private
     */
    var $_xmlFileRes = null;

    /**
     * Index name (unique for a given xml file)
     * @var string
     * @access private
     */
    var $_indexName;    
    
    /**
     * Index file resource
     * @var resource
     * @access private
     */
    var $_indexFileRes;
    
    /**
     * Matching regions of the XML file
     * 
     * @var array
     * @access private
     */
     var $_regions = array();

    /**
     * Number of found matches
     * @var int
     * @access private
     */
    var $_matchesNum = 0; 
     
    /**
     * Counter as used by next()
     *
     * @var int
     * @access private
     * @see XML_Indexing_Reader::next()
     */
    var $_fetchCounter;

    /**
     * Benchmark_Profiler object
     * @var object 
     * @access private
     */
    var $_profiler = null;
     
    /**
     * Nodes extracted with a conventional DOM XPath query
     * @var mixed
     * @access private
     * @see XML_Indexing_Reader::_bypass()
     */
    var $_extractedNodes = null;

    /**
     * PHP5's DOMDocument object
     *
     * Needed by the fetchStrings() method when bypassing indexing
     * @see XML_Indexing_Reader::fetchStrings()
     * @see XML_Indexing_Reader::bypass()
     * var object
     * @access _private
     */
     var $_domDocument;
    
    /**
     * Major PHP version number (either 4 or 5)
     * @var integer
     * @access private
     */
    var $_phpVersion;
     
    /**
     * Constructor
     * 
     * Supported options : 
     *
     * - "dsn" : Index storage strategy, Default is to create a file in the
     *           system default temporary directory (ie: /tmp on *nix),
     *           with a '.xi' suffix.
     *           The only currently supported format is 'file://<path>'.
     *           Example : 'file:///var/cache/xi/%s.xi'
     *           Using the '%s' expression is required.
     * - "gz_level" : Zlib compression level of the index files. 0 by default
     *                (no compression). Goes up to 9 (maximum compression, slow).
     *                Use this if you expect big indexes (many attributes, etc...)
     * - "profiling" : takes a boolean value to enable/disable profiling support.
     *                 Default is false. Enabling this option requires the Benchmark 
     *                 and Console_Table packages. See profile().
     *
     * @param string $filename The XML file to parse
     * @param array  $options  Optional custom options 
     * @access public 
     */
    function XML_Indexing_Reader ($filename, $options = array()) 
    {
        $this->_options = array_merge ($this->_options, $options);
        $this->_phpVersion = substr(phpversion(),0,1);
        if ($this->_options['profiling']) {
            require_once 'Benchmark/Profiler.php';
            $this->_profiler =& new Benchmark_Profiler();
            //unset ($this->_profiler->auto); // workaround for bug #3369
            $this->_profiler->start();
        }
        $this->_enterSection('Constructor');
        if (is_null($this->_options['dsn'])) {
            $tmpdir = File::getTempDir();
            $this->_options['dsn'] = "file://$tmpdir/%s.xi";
        }
        $this->_xmlFilename = $filename;
        
        $real = realpath ($this->_xmlFilename);
        $stat = stat ($real);
        $this->_indexName = md5("$real:{$stat['ctime']}:{$stat['size']}") .
                            ($this->_options['gz_level'] ? '.z' : '');
        
        $this->_loadIndex ();

        $this->_leaveSection('Constructor');
    }

    /**
     * Load the index attached to the current XML file
     * 
     * @access private
     * @return void
     * @see XML_Indexing_Reader::_xmlFilename
     * @see XML_Indexing_Reader::_index
     */
    function _loadIndex () 
    {
        $this->_enterSection('_loadIndex');
        
        $index = array();
        
        $a = strcspn ($this->_options['dsn'], ':');
        $proto = substr ($this->_options['dsn'], 0, $a);
        $path = substr ($this->_options['dsn'], $a + 3);

        switch ($proto) {
            case 'file' : 
                $f = sprintf($path, $this->_indexName);
                $data = '';
                if ($fp = @fopen ($f,'r')) {
                    $this->_enterSection('_loadIndex (acquiring read lock)');
                    flock($fp, LOCK_SH);
                    $this->_leaveSection('_loadIndex (acquiring read lock)');
                    $this->_enterSection('_loadIndex (reading data)');
                    while (!feof($fp)) {
                        $data .= fread ($fp, 0xFFFF);
                    }
                    fclose ($fp);
                    $this->_leaveSection('_loadIndex (reading data)');
                }
                
                $this->_enterSection('_loadIndex (unserializing/uncompressing)');
                if ($data) {
                    if ($this->_options['gz_level']) {
                        $index = unserialize(gzuncompress($data));
                    } else {
                        $index = unserialize($data);
                    }
                }
                $this->_leaveSection('_loadIndex (unserializing/uncompressing)');
                break;
        }
        $this->_index = $index;
        
        $this->_leaveSection('_loadIndex');
    }
    
    /**
     * Save the current index
     *
     * @access private
     * @return void
     * @see XML_Indexing_Reader::_xmlFilename
     * @see XML_Indexing_Reader::_index
     */
    function _saveIndex ()
    {
        $this->_enterSection('_saveIndex');
        
        $a = strcspn ($this->_options['dsn'], ':');
        $proto = substr ($this->_options['dsn'], 0, $a);
        $path = substr ($this->_options['dsn'], $a + 3);

        switch ($proto) {
            case 'file' : 
                if ($this->_options['gz_level']) {
                    $data = gzcompress (serialize($this->_index), 
                                       $this->_options['gz_level']);
                } else {
                    $data = serialize($this->_index);
                }
                fwrite ($this->_indexFileRes, $data);
                fclose ($this->_indexFileRes);
                break;
        }

        $this->_leaveSection('_saveIndex');
    }

    /**
     * Acquire an exclusive lock on the index container
     * @return void
     * @access private
     */
    function _acquireLock ()
    {
        $this->_enterSection('_acquireLock');
        
        $a = strcspn ($this->_options['dsn'], ':');
        $proto = substr ($this->_options['dsn'], 0, $a);
        $path = substr ($this->_options['dsn'], $a + 3);

        if ($proto == 'file') {
            $f = sprintf($path, $this->_indexName);
            $this->_indexFileRes = fopen ($f,'w');
            flock ($this->_indexFileRes, LOCK_EX);
        }
        
        $this->_leaveSection('_acquireLock');
    }
    
    /**
     * Build and save an Index
     * 
     * @param string $type The type of index
     * @param string $root XPath root
     * @param string $attr Optional attribute name
     * @access private
     * @return void
     */
    function _buildIndex ($type, $root = '/', $attr = null)
    {
        $this->_enterSection('_buildIndex');
        
        $this->_acquireLock();
        switch ($type) {
            case 'Numeric':
                require_once "XML/Indexing/Builder/$type.php";
                $builder = new XML_Indexing_Builder_Numeric ($this->_xmlFilename, 
                                                             $root);
                $this->_index[$root]['#'] = $builder->getIndex();
                break;
            case 'Attribute':
                require_once "XML/Indexing/Builder/$type.php";
                $builder = new XML_Indexing_Builder_Attribute ($this->_xmlFilename, 
                                                               $root, $attr);
                $this->_index[$root][$attr] = $builder->getIndex();
                break;
            case 'Namespaces' :
                require_once "XML/Indexing/Builder.php";
                $builder = new XML_Indexing_Builder ($this->_xmlFilename,'/');
        }
        $this->_index['NS'] = $builder->getNamespaces();
        unset ($builder);
        $this->_saveIndex();
        
        $this->_leaveSection('_buildIndex');
    }
    
    /**
     * Search for an XPath expression
     * 
     * @param string $xpath XPath expression to look for
     * @return mixed The number of nodes matched or a PEAR_Error 
     * @access public
     */
    function find ($xpath) 
    {
        $this->_enterSection('find');
        
        $this->_regions = array();
        $this->_fetchCounter = 0;
        $sortRegions = false;
        if (ereg('^([a-zA-Z0-9:._/-]+)$',$xpath, $regs)) {
            $root = $xpath;
            $test = $this->_openXML();
            if (PEAR::isError($test)) {
                $this->_leaveSection('find');
                return $test;
            }
            if (!isset($this->_index[$root]['#'])) {
                $this->_buildIndex ('Numeric',$root);
            }
            foreach ($this->_index[$root]['#'] as $expr => $list) {
                foreach ($list as $spec) {
                    $this->_regions[] = $spec;
                }
            }
        } else if (ereg('^([a-zA-Z0-9:._/-]+)\[(.*)\]$',$xpath, $regs)) {
            $root = $regs[1];
            $expr = $regs[2];
            $test = $this->_openXML();
            if (PEAR::isError($test)) {
                $this->_leaveSection('find');
                return $test;
            }
            if (is_numeric($expr) or $expr == 'last()') {
                if (!isset($this->_index[$root]['#'])) {
                    $this->_buildIndex ('Numeric',$root);
                }
                if ($expr == 'last()') {
                    $expr = count($this->_index[$root]['#']);
                }
                if (isset($this->_index[$root]['#'][$expr])) {
                    foreach ($this->_index[$root]['#'][$expr] as $spec) {
                        $this->_regions[] = $spec;
                    }
                }
            } else if (ereg('^@([a-zA-Z0-9:._-]+)=[\'"](.*)[\'"]$', $expr ,$regs)) {
                $attr = $regs[1];
                $value = stripcslashes($regs[2]);
                if (!isset($this->_index[$root][$attr])) {
                    $this->_buildIndex ('Attribute', $root, $attr);
                }
                if (isset($this->_index[$root][$attr][$value])) {
                    foreach ($this->_index[$root][$attr][$value] as $spec) {
                        $this->_regions[] = $spec;
                    }
                }
            } else if (ereg('^@([a-zA-Z0-9:._-]+)$', $expr ,$regs)) {
                $attr = $regs[1];
                if (!isset($this->_index[$root][$attr])) {
                    $this->_buildIndex ('Attribute', $root, $attr);
                }
                if (!empty($this->_index[$root][$attr])) {
                    foreach ($this->_index[$root][$attr] as $value => $regions) {
                        foreach ($regions as $spec) {
                            $this->_regions[] = $spec;
                        }
                    }
                    $sortRegions = true;
                }
            }
        }
        if ($sortRegions) {
            $this->_sortRegions();        
        }
        if (empty($this->_regions)) {
            $r = $this->_bypass($xpath);
        } else {
            $r = count($this->_regions);
        }
        $this->_matchesNum =  is_integer ($r) ? $r : 0; 
        $this->_leaveSection('find');
        return $r;
    }

    /**
     * Search for an xpath expression, bypassing indexing
     * 
     * @param string $xpathStr XPath expression to look for
     * @access private
     * @return mixed The number of nodes matched or a PEAR_Error 
     */
    function _bypass($xpathStr)
    {
        if ($this->_phpVersion == 5) {
            $doc = DomDocument::load($this->_xmlFilename);
            $xpath = new DomXpath($doc);
            $result = $xpath->query($xpathStr);
            if ($numResults = $result->length) {
                $this->_extractedNodes =& $result;
                $this->_domDocument =& $doc;
            }
            unset ($xpath);
        } else {
            if (!isset ($this->_index['NS'])) {
                // FIXME: need to issue a fatal error if in (future) manual mode
                $this->_buildIndex ('Namespaces');
            }
            require_once 'XML/XPath.php';
            $xpath = new XML_XPath ($this->_xmlFilename,'file');
            if (!empty ($this->_index['NS'])) {
                $xpath->registerNamespace ($this->_index['NS']);
            }
            $result =& $xpath->evaluate ($xpathStr);
            if (PEAR::isError($result)) {
                $numResults = $result;
            } else {
                if ($numResults = $result->numResults()) {
                    $this->_extractedNodes =& $result;
                }
            }
            unset ($result);
            unset ($xpath);
        }
        return $numResults;
    }
    
    /**
     * Acquire a file resource on the xml file
     *
     * @return mixed true or PEAR_Error
     * @access private
     * @see XML_Indexing_Reader::_xmlFileRes
     * @see XML_Indexing_Reader::_xmlFilename
     */
    function _openXML()
    {
        if (!$this->_xmlFileRes) {
            if (!$this->_xmlFileRes = fopen ($this->_xmlFilename, 'r')) {
                return new PEAR_Error('Unable to open the XML File : ' . 
                                      $this->_xmlFilename);
            }
        }
        return true;
    }
    
    /**
     * Sort regions 
     * @return void
     * @access private
     */
    function _sortRegions()
    {
        $this->_enterSection('_sortRegions');
        $ii = count($this->_regions);
        $sortAr = array();
        for ($i=0; $i < $ii; $i++) {
            $sortAr[$i] = $this->_regions[$i][0];
        }
        array_multisort ($sortAr, SORT_NUMERIC, SORT_ASC, $this->_regions);
        $this->_leaveSection('_sortRegions');
    }
    
    /**
     * Retrieves the total number of matches
     *
     * @return int The number of matches
     * @access public
     */
    function count()
    {
        return $this->_matchesNum;
    }

    /**
     * Fetch a set of XML matches as raw strings
     * 
     * @param int $offset The n match to start fetching from (zero based, default : 0)
     * @param int $limit  How many matches to fetch (default : all)
     * @return array Array of XML strings
     */
    function fetchStrings ($offset = 0, $limit = null)
    {
        $this->_enterSection('fetchStrings');
        $result = array();
        if ($this->_extractedNodes) {
            if ($this->_phpVersion == 5) {
                for ($i=$offset; $i < $this->_extractedNodes->length 
                                 and (is_null ($limit) or $i < $offset + $limit); $i++) {
                    $node = $this->_extractedNodes->item($i);                     
                    $result[] = $this->_domDocument->saveXML ($node);
                }
            } else {
                $this->_extractedNodes->rewind();
                for ($i=0; $i < $offset and $this->_extractedNodes->next(); $i++);
                for ($i=0; (is_null($limit) or $i < $limit) 
                           and $this->_extractedNodes->next(); $i++) {
                    $result[] = $this->_extractedNodes->toString(null,false,false);
                }
            }
        } else {
            if (is_null($limit)) { 
                $limit =  count($this->_regions);
            }
            for ($i = $offset; $i < $offset + $limit; $i++) {
                if (isset($this->_regions[$i])) {
                    list ($ofs, $len) = $this->_regions[$i];
                    fseek ($this->_xmlFileRes, $ofs);
                    $result[] = trim(fread ($this->_xmlFileRes, $len));
                }
            }
        }
        $this->_leaveSection('fetchStrings');
        return $result;
    }
   
    /**
     * Fetch a set of XML matches as DOM nodes
     * 
     * @param int $offset The n match to start fetching from (zero based, default : 0)
     * @param int $limit  How many matches to fetch (default : all)
     * @return array DomElements
     * @access public
     */
    function fetchDomNodes ($offset = 0, $limit = null)
    {
        $this->_enterSection('fetchDomNodes');
        $result = array();
        if ($this->_extractedNodes) {
            if ($this->_phpVersion == 5) {
                for ($i=$offset; $i < $this->_extractedNodes->length 
                                 and (is_null($limit) or $i < $offset + $limit); $i++) {
                    $result[] = $this->_extractedNodes->item($i);                     
                }
            } else {
                $this->_extractedNodes->rewind();
                for ($i=0; $i < $offset and $this->_extractedNodes->next(); $i++);
                for ($i=0; (is_null($limit) or $i < $limit)  
                           and $this->_extractedNodes->next(); $i++) {
                    $result[] = $this->_extractedNodes->pointer;
                }
            }
        } else {
            if ($strings = $this->fetchStrings ($offset, $limit)) {
                $reconstructed = '<?xml version="1.0"?>';
                $ns = $this->getNameSpaces();
                $nsDecl = array();
                foreach ($ns as $prefix => $uri) {
                    $nsDecl[] = "xmlns:$prefix=\"$uri\"";
                }
                $reconstructed .= '<root ' . join(' ', $nsDecl) . '>' . 
                                  join('',$strings) . '</root>';

                if ($this->_phpVersion == 5) {
                    $dom = new DomDocument();
                    $dom->loadXml($reconstructed);
                    $nodeset = $dom->documentElement->childNodes;
                    $ii = $nodeset->length;
                    for ($i = 0; $i < $ii; $i++) {
                        $result[] = $nodeset->item($i);
                    }
                } else {
                    // assuming PHP4
                    $dom = domxml_open_mem ($reconstructed);
                    $root = $dom->document_element();
                    $result = $root->child_nodes();
                }
            }
        }
        $this->_leaveSection('fetchDomNodes');
        return $result;
    }
    
    /**
     * Return namespaces declared in the XML file
     * 
     * @return array An associative array of the form ('prefix' => 'uri', ...)
     * @access public
     */
    function getNamespaces ()
    {
        if (isset($this->_index['NS'])) return $this->_index['NS'];
        else return array();
    }
  
    /**
     * Output profiling informations
     *
     * @return void
     * @access public
     */
    function profile()
    {
        if (is_null($this->_profiler)) {
            PEAR::raiseError('You need to enable profiling before calling profile()');
            return;
        }
        $this->_profiler->stop();
        $info = $this->_profiler->getAllSectionsInformations();
        require_once 'Console/Table.php';
        $table = new Console_Table();
        $table->setHeaders (array('Method', 'Netto Time (ms)', 'Time (ms)', 'Percentage'));
        foreach ($info as $method => $details) {
            extract($details);
            $table->addRow(array ($method, 
                                  str_pad(number_format($netto_time * 1000,2),15,' ',STR_PAD_LEFT), 
                                  str_pad(number_format($time * 1000,2),10,' ',STR_PAD_LEFT), 
                                  str_pad($percentage,10,' ',STR_PAD_LEFT)));
        }
        echo $table->getTable();
    }

    /**
     * Wrapper for Benchmark_Profiler::enterSection()
     *
     * @param string $name Section name
     * @access private
     * @return void
     */
    function _enterSection($name)
    {
        if (!is_null($this->_profiler)) {
            $this->_profiler->enterSection($name);
        }
    }

    /**
     * Wrapper for Benchmark_Profiler::leaveSection()
     *
     * @param string $name Section name
     * @access private
     * @return void
     */
    function _leaveSection($name)
    {
        if (!is_null($this->_profiler)) {
            $this->_profiler->leaveSection($name);
        }
    }
}

?>
