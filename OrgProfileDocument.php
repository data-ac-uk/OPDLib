<?php


define ('UTF32_BIG_ENDIAN_BOM'   , chr(0x00) . chr(0x00) . chr(0xFE) . chr(0xFF));
define ('UTF32_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE) . chr(0x00) . chr(0x00));
define ('UTF16_BIG_ENDIAN_BOM'   , chr(0xFE) . chr(0xFF));
define ('UTF16_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE));
define ('UTF8_BOM'               , chr(0xEF) . chr(0xBB) . chr(0xBF));

function detect_utf_encoding($text) {

    $first2 = substr($text, 0, 2);
    $first3 = substr($text, 0, 3);
    $first4 = substr($text, 0, 4);
    
    if ($first3 == UTF8_BOM) return 'UTF-8';
    elseif ($first4 == UTF32_BIG_ENDIAN_BOM) return 'UTF-32BE';
    elseif ($first4 == UTF32_LITTLE_ENDIAN_BOM) return 'UTF-32LE';
    elseif ($first2 == UTF16_BIG_ENDIAN_BOM) return 'UTF-16BE';
    elseif ($first2 == UTF16_LITTLE_ENDIAN_BOM) return 'UTF-16LE';
	else return false;
}
function detect_utf_encoding_and_remove($text) {

    $first2 = substr($text, 0, 2);
    $first3 = substr($text, 0, 3);
    $first4 = substr($text, 0, 4);
    
    if ($first3 == UTF8_BOM) return substr($text, 3);
    elseif ($first4 == UTF32_BIG_ENDIAN_BOM) return substr($text, 4);
    elseif ($first4 == UTF32_LITTLE_ENDIAN_BOM) return substr($text, 4);
    elseif ($first2 == UTF16_BIG_ENDIAN_BOM) return substr($text, 2);
    elseif ($first2 == UTF16_LITTLE_ENDIAN_BOM) return substr($text, 2);
	else return $text;
}



class OrgProfileDocument
{

# allowed $from:
#  url
#  result
#  string
function __construct( $param, $from = "url" )
{
	if( $from == "url" )
	{
		
		$this->opd_url = $param;
		$this->result = OrgProfileDocument::get_url( $param );
		if( $this->result["HTTP_CODE"] != "200" )
		{
			throw new OPD_Load_Exception( "Failed to load document: Error ".$this->result["HTTP_CODE"] );
		}
	}

	if( $from == "result"  )
	{
		$this->opd_url = $param["EFFECTIVE_URL"];
		$this->result = $param;
	}

	$parse_as = "Turtle";
	if( $from == "result" || $from == "url" )
	{
		$effective_url = $this->result["EFFECTIVE_URL"];
		if( stristr($this->result["CONTENT_TYPE"],"application/xml") !== FALSE  || 
			 stristr($this->result["CONTENT_TYPE"],"application/rdf+xml") !== FALSE ) { $parse_as = "RDFXML"; }
		$document = detect_utf_encoding_and_remove($this->result["CONTENT"]);
	}
	elseif( $from == "local" )
	{
		$document = file_get_contents($param);
		$effective_url = "http://example.org/opd";
	}
	elseif( $from == "string" )
	{
		$document = $param;
		$effective_url = "http://example.org/opd";
		
	}
	else
	{
		throw new OPD_Exception( "Unknown value for 'from': '$from'" );
	}

	
	$this->graph = new Graphite();
	$this->graph->ns( "aiiso", "http://purl.org/vocab/aiiso/schema#" );
	$this->graph->ns( "org", "http://www.w3.org/ns/org#" );

	$this->n = 0;
	if( $parse_as == "RDFXML" )
	{
		$this->n = $this->graph->addRDFXML( $effective_url, $document );
	}
	elseif( $parse_as == "Turtle" )
	{
		
		$this->n = $this->graph->addTurtle( $effective_url, $document );
	}
	else
	{
		throw new OPD_Exception( "Unknown parse_as value: '$parse_as'." );
	}

	# assume turtle.
	if( $this->n == 0 ) 
	{
		throw new OPD_Parse_Exception( "Failed to parse OPD as a $parse_as document.", $parse_as, $document );
	}

	$profileDocument = $this->graph->allOfType( "oo:OrganizationProfileDocument" );
	if( count($profileDocument) == 0 )
	{
		throw new OPD_Parse_Exception( "Document contains no oo:OrganizationProfileDocument.", $parse_as, $document );
	}

	if( count($profileDocument) > 1 )
	{
		throw new OPD_Parse_Exception( "Document contains more than one oo:OrganizationProfileDocument.", $parse_as, $document );
	}

	$this->doc = $profileDocument[0];

	if( !$this->doc->has( "foaf:primaryTopic" ) )
	{
		throw new OPD_Parse_Exception( "oo:OrganizationProfileDocument does not have a foaf:primaryTotpic.", $parse_as, $document );
	}

	$this->org = $this->doc->get( "foaf:primaryTopic" );
}
	
static function from_string( $string )
{
	return new OrgProfileDocument( $string, "string" );
}	

static function discover( $url )
{
	$ok = preg_match( "/^(https?:\/\/[-a-z0-9\.]+)/", $url, $bits );
	if( !$ok )
	{
		throw new OPD_Discover_Exception( "Discovery URL does not appear valid." );
	}
	$homepage = $bits[1]."/";
	$result = OrgProfileDocument::get_url( $homepage );
	
	if( $result["HTTP_CODE"] == "200" )
	{
		# Ok. step 1, try .well-known/openorg
		$content = preg_replace( "/\n/", ' ', $result["CONTENT"] );
		$links = array();
		preg_replace( "/<link([^>]+)>/e", '$links []= "$1";', $content );
		foreach( $links as $link )
		{
			$link = preg_replace( '/\\\\\'/', '"', $link );
			$l = array();
			preg_replace( "/([a-z]+)\s*=\s*(\"([^\"]+)\"|([^\s]+))/ei", '$l["$1"] = "$3$4"', $link );
			if( @ $l["rel"] == "" ) { continue; }

			# use url_to_absolute if available otherwise busk it
			if( @ $l["href"] )
			{
				if( function_exists( "url_to_absolute" ) )
				{
					# if the url to absolute library is available, obviously
					# we'll use that.
					$l["href"] = url_to_absolute( $homepage, $l["href"] );
				}
				else
				{
					# otherwise busk it, unless it starts with https? in which
					# case no action is required.
					if( ! preg_match( "/^https?:/", $l["href"] ) )
					{
						$l["href"] = $homepage . preg_replace( "/^\//", "", $l["href"] );
					}
				}
			}
			$linkdata[$l["rel"]][]= $l;
		}

		if( @$linkdata["openorg"][0]["href"] )
		{
			$opd_url = $linkdata["openorg"][0]["href"];
	
			$opd = new OrgProfileDocument( $opd_url, "url" );
			$opd->discovery = "LINK";
			return $opd;
		
			//throw new OPD_Discover_Exception( "Failed to discover via well-known. Homepage loaded OK but had no rel='openorg' link." );
		}
	}else{
		throw new OPD_Discover_Exception( "Failed to load homepage with error ".$result["HTTP_CODE"].". Didn't bother looking at the .well-know as homepage not working." );
	}
	
	$wt = $homepage.".well-known/openorg";
	$result = OrgProfileDocument::get_url( $wt );

	if( $result["HTTP_CODE"] == "200" )
	{
		$opd = new OrgProfileDocument( $result, "result" );
		$opd->discovery = "WELL-KNOWN";
		return $opd;
	}

	# could not load the homepage, weird but can happen
	throw new OPD_Discover_Exception( "Couldn't find a rel='openorg' link tag and also failed to discover via well-known." );
	
}

private static function get_url($url)
{
	global $last_httpCode;
	$process = curl_init($url);
	$headers = array();
	$headers[] = 'Accept: text/turtle, application/rdf+xml, */*;q=0.1';
	curl_setopt($process, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($process, CURLOPT_HEADER, 0);
	curl_setopt($process, CURLOPT_TIMEOUT, 30);
	curl_setopt($process, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($process, CURLOPT_FOLLOWLOCATION, 1);

	$r = array();
	$r["CONTENT"] = curl_exec($process);
	$r["HTTP_CODE"] = curl_getinfo($process, CURLINFO_HTTP_CODE);
	$r["EFFECTIVE_URL"] = curl_getinfo($process, CURLINFO_EFFECTIVE_URL);
	$r["CONTENT_TYPE"] = curl_getinfo($process, CURLINFO_CONTENT_TYPE);
	$r["CONTENT_LENGTH_DOWNLOAD"] = curl_getinfo($process, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
	curl_close($process);

	return $r;
}

function datasets( $subjects )
{
	if( !is_array( $subjects ) ) { $subjects = array( $subjects ); }
	$datasets = array();

	foreach( $this->org->all( "-oo:organization" ) as $org_thing )
	{
		foreach( $org_thing->all( "dcterms:subject" ) as $thing_subject )
		{
			foreach( $subjects as $wanted_subject )
			{
				if( strcasecmp($thing_subject, $wanted_subject) == 0 )
				{
					$datasets[] = $org_thing;
					continue(2);
				}
			}	
		}
	}
	return $datasets;
}

function datasetsBySubject( $subjects )
{
	if( !is_array( $subjects ) ) { $subjects = array( $subjects ); }
	$datasets = array();

	foreach( $this->org->all( "-oo:organization" ) as $org_thing )
	{
		foreach( $org_thing->all( "dcterms:subject" ) as $thing_subject )
		{
			foreach( $subjects as $wanted_subject )
			{
				if( strcasecmp($thing_subject, $wanted_subject) == 0 )
				{
					$datasets[$wanted_subject][] = $org_thing;
					continue(2);
				}
			}	
		}
	}
	return $datasets;
}


}

class OPD_Exception extends Exception
{
}

class OPD_Discover_Exception extends OPD_Exception
{
}

class OPD_Load_Exception extends OPD_Exception
{
}

class OPD_Parse_Exception extends OPD_Exception
{
	var $format;
	var $document;
	function __construct( $msg, $format, $document )
	{
		$this->format = $format;
		$this->document = $document;
		parent::__construct( $msg );
	}
}

