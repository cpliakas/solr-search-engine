Overview
========

This library integrates the Solarium project with the Search Framework library.

```php

// Index an RSS feed into Solr.

// @see https://github.com/cpliakas/feed-collection
use Search\Collection\Feed\FeedCollection;
use Search\Server\Solarium\SolariumSearchServer;

require 'vendor/autoload.php';

$options = array(
    'endpoint' => array(
        'localhost' => array(
            'scheme' => 'http',
            'host' =>'localhost',
            'port' => 8983,
            'path' => '/solr',
        ),
    ),
);
$solr = new SolariumSearchServer($options);

// Associate the collection with the Solr server.
$drupal_planet = new FeedCollection();
$drupal_planet->setFeedUrl('http://drupal.org/planet/rss.xml');
$solr->addCollection($drupal_planet);

// Index the feeds into Solr.
$solr->index();
```
