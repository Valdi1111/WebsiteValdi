import { API_URL } from "@BooksBundle/constants";
import axios from "axios";

export async function getBooksAll(limit, offset) {
    return axios.get(`${API_URL}/books/all?limit=${limit}&offset=${offset}`);
}

export async function getBooksNotInShelf(limit, offset) {
    return axios.get(`${API_URL}/books/not-in-shelves?limit=${limit}&offset=${offset}`);
}

export async function findNewBooks() {
    return axios.get(`${API_URL}/books/find-new`);
}
