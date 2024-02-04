import {API_URL} from "./constants";

export function getListAnime() {

}

export function getListManga() {

}

export function getEpisodeDownloads() {
    return fetch(`${API_URL}/downloads`).then(res => res.json());
}