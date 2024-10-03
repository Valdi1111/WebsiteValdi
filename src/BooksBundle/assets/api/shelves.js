import { API_URL } from "@BooksBundle/constants";
import axios from "axios";

export async function getShelves() {
    return axios.get(`${API_URL}/shelves`);
}

export async function getBooksInShelf(id) {
    return axios.get(`${API_URL}/shelves/${id}/books`);
}

export async function addShelf(path, name) {
    return axios.post(
        `${API_URL}/shelves`,
        { path, name },
    );
}

export async function editShelf(id, name) {
    return axios.put(
        `${API_URL}/shelves/${id}`,
        { name },
    );
}

export async function deleteShelf(id) {
    return axios.delete(`${API_URL}/shelves/${id}`);
}
