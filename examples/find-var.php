#!/usr/bin/php
<?php

# change these to your arc2 & graphite locations
require_once( "/home/cjg/Projects/CouncilData/arc2/ARC2.php" );
require_once( "/home/cjg/public_html/Graphite/Graphite.php" );

require_once( "../OrgProfileDocument.php" );

if( sizeof( $argv ) != 3 )
{
	print "find-dataset.php <homepage> <term>\n";
	print "   eg. find-dataset.php http://www.southampton.ac.uk/ http://purl.org/linkingyou/ research\n";
	exit(1);
}

$homepage = $argv[1];
$term = $argv[2];

try {
	$opd = OrgProfileDocument::discover( $homepage );
}
catch( OPD_Discover_Exception $e )
{
        print "Failed to discover OPD: ".$e->getMessage()."\n";
        exit( 4 );
}
catch( OPD_Load_Exception $e )
{
        print "Failed to load OPD: ".$e->getMessage()."\n";
        exit( 3 );
}
catch( OPD_Parse_Exception $e )
{
        print "Failed to parse OPD: ".$e->getMessage()."\n";
        # could print out $e->document ?
        exit( 2 );
}
catch( Exception $e )
{
        print "Error: ".$e->getMessage()."\n";
        exit( 1 );
}


print $opd->org->get($term)."\n";

exit;
