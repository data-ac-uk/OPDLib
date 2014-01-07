#!/usr/bin/php
<?php

# change these to your arc2 & graphite locations
require_once( "../../../lib/arc2/ARC2.php" );
require_once( "../../../lib/Graphite/Graphite.php" );

require_once( "../OrgProfileDocument.php" );

if( sizeof( $argv ) != 3 )
{
	print "find-dataset.php <homepage> <theme>\n";
	print "   eg. find-dataset.php http://www.southampton.ac.uk/ equipment\n";
	exit(1);
}

$homepage = $argv[1];
$theme = $argv[2];

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


print "\n";
print "OPD Loaded OK for $homepage\n";
print "OPD Location: {$opd->opd_url}\n\n";
$datasets = $opd->datasets( "http://purl.org/openorg/theme/".$theme );

if( sizeof( $datasets ) == 0)
{
	print "No '$theme' datasets found.\n";
	exit( 5 );
}

print $opd->org->label()." '".$theme."' Datasets:\n\n";
foreach( $datasets as $dataset )
{
	print "URL: $dataset\n";
	print "License: ".$dataset->get( "dcterms:license" )."\n";
	print "Conforms to: ".$dataset->get( "dcterms:conformsTo" )."\n";
	print "\n";
}

exit( 0 );
