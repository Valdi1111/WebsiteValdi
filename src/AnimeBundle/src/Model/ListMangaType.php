<?php

namespace App\AnimeBundle\Model;

enum ListMangaType: string
{
    case unknown = 'unknown';
    case manga = 'manga';
    case light_novel = 'light_novel';
    case novel = 'novel';
    case one_shot = 'one_shot';
    case doujinshi = 'doujinshi';
    case manhwa = 'manhwa';
    case manhua = 'manhua';
    case oel = 'oel';
}
