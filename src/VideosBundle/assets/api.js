import {API_URL} from "./constants";

export function getFiles(path) {
    return fetch(`${API_URL}/files${path}`).then(res => res.json());
}