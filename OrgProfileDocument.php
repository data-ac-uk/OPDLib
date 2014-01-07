<?php

class OrgProfileDocument
{

# allowed $from:
#  url
#  result
#  string
function __construct( $param, $from = "url" )
{
	
	if( $from == "local"){
		$this->result = OrgProfileDocument::get_local( $param );
		if( $this->result["STATUS"] != "ok" )
		{
			throw new OPD_Load_Exception( "Failed to load document: Error ".$this->result["STATUS"] );
		}
	}
	
	if( $from == "url" )
	{
		$this->result = OrgProfileDocument::get_url( $param );
		if( $this->result["HTTP_CODE"] != "200" )
		{
			throw new OPD_Load_Exception( "Failed to load document: Error ".$this->result["HTTP_CODE"] );
		}
	}

	if( $from == "result"  )
	{
		$this->result = $param;
	}

	$parse_as = "Turtle";
	if( in_array($from, array("result","url","local") ) )
	{
		$effective_url = $this->result["EFFECTIVE_URL"];
		if( $this->result["CONTENT_TYPE"]=="application/rdf+xml" ) { $parse_as = "RDFXML"; }
		$document = $this->result["CONTENT"];
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

static function autodiscover ( $homepages ) {
	
	$opds = array(); 
	
	foreach($homepages as $url)
	{
		try{ 
			$opd = OrgProfileDocument::discover( $url );
		}
		catch( OPD_Discover_Exception $e )
		{
			continue;
		}
		catch( OPD_Load_Exception $e )
		{
			continue;
		}
		catch( OPD_Parse_Exception $e )
		{
		    continue;
		}
		catch( Exception $e )
		{
			continue;
		}
	
		$opds[] = $opd->opd_url;

	}
	
	return $opds;
}

static function discover( $url )
{
	$ok = preg_match( "/^(https?:\/\/[-a-z0-9\.]+)/", $url, $bits );
	if( !$ok )
	{
		throw new OPD_Discover_Exception( "Discovery URL does not appear valid." );
	}
	$homepage = $bits[1]."/";

	# Ok. step 1, try .well-known/openorg
	$wt = $homepage.".well-known/openorg";
	$result = OrgProfileDocument::get_url( $wt );

	if( $result["HTTP_CODE"] == "200" )
	{
		$opd = new OrgProfileDocument( $result, "result" );
		$opd->discovery = "WELL-KNOWN";
		$opd->opd_url = $result['EFFECTIVE_URL'];
		return $opd;
	}

	# well, didn't find it that way, lets try through the homepage
	$result = OrgProfileDocument::get_url( $homepage );

	if( $result["HTTP_CODE"] != "200" )
	{
		# could not load the homepage, weird but can happen
		throw new OPD_Discover_Exception( "Failed to discover via well-known. Failed to load homepage with error ".$result["HTTP_CODE"]."." );
	}

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

	if( !@$linkdata["openorg"][0]["href"] )
	{
		throw new OPD_Discover_Exception( "Failed to discover via well-known. Homepage loaded OK but had no rel='openorg' link." );
	}
 	$opd_url = $linkdata["openorg"][0]["href"];

	$opd = new OrgProfileDocument( $opd_url, "url" );
	$opd->discovery = "LINK";
	
	$opd->opd_url = $linkdata["openorg"][0]["href"];
	return $opd;
}


private static function get_local($path)
{
	$ret = array();
	if(!file_exists($path)){
		$ret['STATUS'] = 'Error: File Not Found';
		return $ret;
	}
	
	$ret['STATUS'] = 'ok';
	$ret['EFFECTIVE_URL'] = $path;
	$ret['CONTENT_TYPE'] = trim(shell_exec("file -bi " . escapeshellarg( $path )));
	$ret['CONTENT_LENGTH_DOWNLOAD'] = filesize($path);
	$ret['CONTENT'] = file_get_contents($path);
	return $ret;
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
				if( $thing_subject == $wanted_subject )
				{
					$datasets []= $org_thing;
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

