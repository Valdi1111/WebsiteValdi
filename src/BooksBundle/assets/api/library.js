import {API_URL} from "../constants";
import axios from "axios";

export const MISSING_COVER_URL = '/images/books/missing-cover.png';
export const BOOKS_PER_PAGE = 20;

export function getCoverUrl(id) {
    return `${API_URL}/books/${id}/cover`;
}

export async function getBooksAll(limit, offset) {
    return axios.get(
        `${API_URL}/books/all?limit=${limit}&offset=${offset}`,
        {}
    );
}

export async function getBooksNotInShelf(limit, offset) {
    return axios.get(
        `${API_URL}/books/not-in-shelves?limit=${limit}&offset=${offset}`,
        {}
    );
}

export async function findNewBooks() {
    return axios.get(
        `${API_URL}/books/find-new`,
        {}
    );
}
