export default [
    {
        path: '/admin/items/show/:id/composer',
        redirect: (to) => ({ name: 'admin.items.composer', params: { id: to.params.id } }),
    },
];
