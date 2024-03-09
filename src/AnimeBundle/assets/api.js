import {API_URL} from "./constants";

export function getEpisodeDownloads() {
    return fetch(`${API_URL}/downloads`).then(res => res.json());
}