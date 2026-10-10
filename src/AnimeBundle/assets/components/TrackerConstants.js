export const TRACKER_CATALOG = {
    myanimelist: {
        key: "myanimelist",
        label: "MyAnimeList",
        shortLabel: "MAL",
        animeUrlPattern: /^https:\/\/myanimelist\.net\/anime\/(\d+)(?:\/.*)?$/,
        mangaUrlPattern: /^https:\/\/myanimelist\.net\/manga\/(\d+)(?:\/.*)?$/,
        animePlaceholder: "https://myanimelist.net/anime/xxxxx",
        mangaPlaceholder: "https://myanimelist.net/manga/xxxxx",
        buildAnimeUrl: (id) => `https://myanimelist.net/anime/${id}`,
        buildMangaUrl: (id) => `https://myanimelist.net/manga/${id}`,
    },
    anilist: {
        key: "anilist",
        label: "AniList",
        shortLabel: "AL",
        animeUrlPattern: /^https:\/\/anilist\.co\/anime\/(\d+)(?:\/.*)?$/,
        mangaUrlPattern: /^https:\/\/anilist\.co\/manga\/(\d+)(?:\/.*)?$/,
        animePlaceholder: "https://anilist.co/anime/xxxxx",
        mangaPlaceholder: "https://anilist.co/manga/xxxxx",
        buildAnimeUrl: (id) => `https://anilist.co/anime/${id}`,
        buildMangaUrl: (id) => `https://anilist.co/manga/${id}`,
    },
};
