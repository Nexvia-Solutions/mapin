import axios from 'axios';

export function loadDashboard() {
    return axios.get('/dashboard');
}

export function createBook(payload) {
    return axios.post('/books', payload);
}

export function loadDashboardViaFetch() {
    return fetch('/dashboard');
}

export function createBookViaFetch(payload) {
    return fetch('/books', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export function createBookViaJquery(payload) {
    return $.ajax({
        url: '/books',
        type: 'POST',
        data: payload,
    });
}

export function loadDashboardViaJqueryShorthand() {
    return $.get('/dashboard');
}

export function ambiguousCall() {
    return $.ajax({ url: '/ambiguous' });
}

export function showCartLike() {
    return axios.get('/cart-like');
}

export function storeCartLike(payload) {
    return axios.post('/cart-like', payload);
}

export function externalCall() {
    return fetch('https://example.com/api/data');
}
