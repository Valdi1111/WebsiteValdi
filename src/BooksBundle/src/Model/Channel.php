<?php

namespace App\BooksBundle\Model;

class Channel
{

    const string LIBRARY_ALL = 'https://books.valdi.ovh/library/all';
    const string LIBRARY_SHELVES = 'https://books.valdi.ovh/library/shelves';
    const string LIBRARY_SHELVES_ID = 'https://books.valdi.ovh/library/shelves/%d';
    const string LIBRARY_NOT_IN_SHELVES = 'https://books.valdi.ovh/library/not-in-shelves';

}