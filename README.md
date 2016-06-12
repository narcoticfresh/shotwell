# shotwell - a PHP library for Shotwell databases

[![PHP version](https://badge.fury.io/ph/narcoticfresh%2Fshotwell.svg)](https://badge.fury.io/ph/narcoticfresh%2Fshotwell)

This is a simple PHP library for dealing with Shotwell (the default photo manager in Ubuntu) sqlite databases.

## Basic stuff

### Why does it exist?

I'm using Shotwell for my pictures and videos (having a huge collection) and I think it's superb!

I found myself in need to use the data stored in the Shotwell database (that btw usually resides in *~/.local/share/shotwell/data/photo.db*)
to script some stuff (like tagging video files with the tags I've given in Shotwell - a thing that it doesn't seem to be able to do).

Being initially pleased that all the data I accumulated is an an re-usable format (a SQLite database), I quickly became puzzled
with the structure of that said database. The storage of the different media types (video/photo) and the relations are rather unusual.

To make matters simpler, this small library was created.

### Is it fully featured?

No. It's a simple thing that hides some Shotwell internal complexity and then gives back plain arrays of the database content.

I didn't need more - I thought about creating custom Models to represent the data structures, but it doesn't make any sense.
Also, only the functions I needed (mostly in regard to basic manipulations and tagging) are implemented.

### Why is it here?

It's only here because I needed the library on more than one of my private projects. To make that dependency stuff easier, it had to 
go on Packagist so it had to go somewhere. So that somewhere is here.

## Installation

Just use composer, see the [Packagist page](https://packagist.org/packages/narcoticfresh/shotwell).

Require it like this:

```
composer require narcoticfresh/shotwell
```

## Usage example

```php
<?php

// make sure composer autoloader is there
require_once 'vendor/autoload.php';

$shotwell = new \Narcoticfresh\Shotwell\Api('/home/user/.local/share/shotwell/data/photo.db');

// get everything
$items = $shotwell->getAll();

// get only photos
$items = $shotwell->getAllPhotos();

// get only videos
$items = $shotwell->getAllVideos();

// get by a tag
$items = $shotwell->getItemsByTag('diving');

// get all tags
$tags = $shotwell->getAllTags();

// set tag on an object
$shotwell->setItemTags('my-object-id', ['tag1', 'tag2']);

// set item rating
$shotwell->setItemRating('my-object-id', 3);
```

