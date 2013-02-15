Overview
========

This library integrates the Solarium project with the Search Framework library.
The following code is an example of how to index RSS feeds into Solr.

```php

use Search\Framework\Indexer;
use Search\Framework\SearchServiceEndpoint;

use Search\Collection\Feed\FeedCollection; // @see https://github.com/cpliakas/feed-collection
use Search\Engine\Solr\Solr;

require 'vendor/autoload.php';

// Instantiate a collection that references the Drupal Planet feed. Collections
// are simply connectors to and models of the source data being indexed.
$drupal_planet = new FeedCollection('feed.drupal');
$drupal_planet->setFeedUrl('http://drupal.org/planet/rss.xml');

// Connect to a Solr server.
$solr = new Solr(new SearchEngineEndpoint('local', 'http://localhost', '/solr', 8983));

// Instantiate an indexer, attach the collection, and index it.
$indexer = new Indexer($solr);
$indexer->attachCollection($drupal_planet);
$indexer->createIndex();
$indexer->index();
```
