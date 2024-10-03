import { API_URL } from "./constants";
import axios from "axios";
import qs from "qs";

export function getDownloads(params) {
    return axios.get(`${API_URL}/downloads?${qs.stringify(params)}`);
}