import axios from "@BooksBundle/api/axios";

export async function getShelves() {
    return axios.get(`/shelves`);
}

export async function getBooksInShelf(id) {
    return axios.get(`/shelves/${id}/books`);
}

export async function addShelf(path, name) {
    return axios.post(
        `/shelves`,
        { path, name },
    );
}

export async function editShelf(id, name) {
    return axios.put(
        `/shelves/${id}`,
        { name },
    );
}

export async function deleteShelf(id) {
    return axios.delete(`/shelves/${id}`);
}
