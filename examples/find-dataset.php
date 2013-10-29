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

$opd = OrgProfileDocument::discover( $argv[1] );

print "\n";
print $opd->org->label()." '".$argv[2]."' Datasets:\n\n";
foreach( $opd->datasets( "http://purl.org/openorg/theme/".$argv[2]) as $dataset )
{
	print "URL: $dataset\n";
	print "License: ".$dataset->get( "dcterms:license" )."\n";
	print "Conforms to: ".$dataset->get( "dcterms:conformsTo" )."\n";
	print "\n";
}
