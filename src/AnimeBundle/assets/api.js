import { API_URL } from "./constants";
import axios from "axios";
import qs from "qs";

export function getListAnime(params) {
    return axios.get(`${API_URL}/list/anime?${qs.stringify(params)}`);
}

export function getSeasonFolders(params) {
    return axios.get(`${API_URL}/list/anime/folders?${qs.stringify(params)}`);
}

export function getListManga(params) {
    return axios.get(`${API_URL}/list/manga?${qs.stringify(params)}`);
}

export function getDownloads(params) {
    return axios.get(`${API_URL}/downloads?${qs.stringify(params)}`);
}