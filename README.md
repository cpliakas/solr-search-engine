Overview
========

This library integrates the Solarium project with the Search Framework library.

```php

// Index an RSS feed into Solr.

// @see https://github.com/cpliakas/feed-collection
use Search\Collection\Feed\FeedCollection;
use Search\Framework\SearchServiceEndpoint;
use Search\Service\Solr\SolrSearchService;

require 'vendor/autoload.php';

$endpoint = new SearchServiceEndpoint('local', 'http://localhost', '/solr', 8983);
$solr = new SolrSearchServer($endpoint);

// Associate the collection with the Solr server.
$drupal_planet = new FeedCollection();
$drupal_planet->setFeedUrl('http://drupal.org/planet/rss.xml');
$solr->attachCollection($drupal_planet);

// Index the feeds into Solr.
$solr->index();
```
