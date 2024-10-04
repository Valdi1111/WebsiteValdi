import axios from "@BooksBundle/api/axios";

export async function getBooksAll(limit, offset) {
    return axios.get(`/books/all?limit=${limit}&offset=${offset}`);
}

export async function getBooksNotInShelf(limit, offset) {
    return axios.get(`/books/not-in-shelves?limit=${limit}&offset=${offset}`);
}

export async function findNewBooks() {
    return axios.get(`/books/find-new`);
}
