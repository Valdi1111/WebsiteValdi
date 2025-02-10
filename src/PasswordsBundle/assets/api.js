import axios from "@PasswordsBundle/axios";
import qs from "qs";

export async function getCredentials(params) {
    return axios.get(`/credentials?${qs.stringify(params)}`);
}

export async function getCredential(id) {
    return axios.get(`/credentials/${id}`);
}

export async function addCredential(data) {
    return axios.post(`/credentials`, data);
}

export async function editCredential(id, data) {
    return axios.put(`/credentials/${id}`, data);
}