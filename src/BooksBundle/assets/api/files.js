import axios from "@BooksBundle/api/axios";

export async function getFolders(id = '/') {
    return axios.get(`/files/folders`, {params: {id}});
}

export async function getFiles(id = '/') {
    return axios.get(`/files/files`, {params: {id}});
}

export async function getDirect(id, download) {
    return axios.get(`/files/direct`, {params: {id, download}});
}
