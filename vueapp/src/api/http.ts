import axios from 'axios';

const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://localhost/api',
  timeout: 1800000, // since we will be uploading large files, set timeout to 30 minutes
  withCredentials: false,
  headers: {
    Accept: 'application/json',
  },
});

export default client;
