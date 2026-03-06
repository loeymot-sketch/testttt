"use strict";

document.addEventListener('click', function (e) {
    const dropdownButton = e.target.closest('.dropdown-btn');

    if (dropdownButton) {
        e.stopPropagation();

        const dropdownGroup = dropdownButton.parentElement;
        const dropdownList = dropdownGroup.querySelector('.dropdown-list');

        if (!dropdownList.classList.contains('active')) {
            const allDropdownLists = document.querySelectorAll('.dropdown-list');
            allDropdownLists.forEach(list => list.classList.remove('active'));
            document.querySelectorAll('.dropdown-btn').forEach(btn => btn.classList.remove('rotated'));
        }

        dropdownList.classList.toggle('active');
        dropdownButton.classList.toggle('rotated');
    } else {
        document.querySelectorAll('.dropdown-list').forEach(function (list) {
            list.classList.remove('active');
            document.querySelectorAll('.dropdown-btn').forEach(btn => btn.classList.remove('rotated'));
        });
    }
});
