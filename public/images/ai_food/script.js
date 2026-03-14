// Chargement du menu depuis le fichier JSON
document.addEventListener('DOMContentLoaded', function() {
    // Charger le menu
    fetch('menu.json')
        .then(response => response.json())
        .then(data => {
            // Stocker les données du menu
            window.menuData = data;
            
            // Afficher la première catégorie par défaut (Tacos)
            displayMenuCategory('tacos');
            
            // Configurer les boutons de navigation du menu
            setupMenuTabs();
            
            // Configurer le bouton Uber Eats
            setupUberEatsButton(data.contact?.uber_eats);
            
            // Configurer le numéro de téléphone
            setupPhoneNumber(data.contact?.phone);
        })
        .catch(error => console.error('Erreur lors du chargement du menu:', error));
    
    // Configurer le menu mobile
    setupMobileMenu();
});

// Afficher une catégorie spécifique du menu
function displayMenuCategory(categoryName) {
    if (!window.menuData) return;
    
    const menuContainer = document.querySelector('.menu-items');
    menuContainer.innerHTML = '';
    
    // Définir les images pour chaque type de produit
    const productImages = {
        'tacos': {
            'default': 'images/tacos_viande.png',
            'poulet': 'images/tacos_poulet.png',
            'viande': 'images/tacos_viande.png',
            'mixte': 'images/tacos_mixte.png'
        },
        'sandwich': {
            'default': 'images/sandwich_kebab.png',
            'kebab': 'images/sandwich_kebab.png',
            'poulet': 'images/sandwich_poulet.png'
        },
        'panini': {
            'default': 'images/panini_francais.png',
            'fromage': 'images/panini_francais.png',
            'jambon': 'images/panini_francais.png',
            'kebab': 'images/panini_kebab.png'
        },
        'dessert': {
            'default': 'images/tiramisu_dessert.png',
            'tiramisu': 'images/tiramisu_dessert.png',
            'mousse': 'images/mousse_chocolat.png',
            'chocolat': 'images/mousse_chocolat.png',
            'tarte': 'images/tarte_pommes.png',
            'pomme': 'images/tarte_pommes.png'
        },
        'boisson': {
            'default': 'images/coca_canette.png',
            'coca': 'images/coca_canette.png',
            'cola': 'images/coca_canette.png',
            'fanta': 'images/fanta_canette.png',
            'sprite': 'images/sprite_canette.png',
            'eau': 'images/eau_minerale.png'
        }
    };
    
    // Trouver la catégorie correspondante
    let category;
    if (categoryName === 'tacos') {
        // Combiner les menus tacos et tacos individuels
        const menusTacos = window.menuData.categories.find(cat => cat.name === 'Menus tacos');
        const tacos = window.menuData.categories.find(cat => cat.name === 'Tacos');
        
        if (menusTacos && tacos) {
            category = {
                name: 'Tacos',
                items: [...menusTacos.items, ...tacos.items]
            };
        }
    } else if (categoryName === 'sandwichs') {
        // Combiner les menus sandwichs et sandwichs individuels
        const menusSandwichs = window.menuData.categories.find(cat => cat.name === 'Menus sandwichs');
        const sandwichs = window.menuData.categories.find(cat => cat.name === 'Sandwichs');
        
        if (menusSandwichs && sandwichs) {
            category = {
                name: 'Sandwichs',
                items: [...menusSandwichs.items, ...sandwichs.items]
            };
        }
    } else if (categoryName === 'paninis') {
        category = window.menuData.categories.find(cat => cat.name === 'Paninis');
    } else if (categoryName === 'desserts') {
        category = window.menuData.categories.find(cat => cat.name === 'Desserts');
    } else if (categoryName === 'boissons') {
        category = window.menuData.categories.find(cat => cat.name === 'Boissons');
    }
    
    if (!category) return;
    
    // Créer les éléments du menu pour cette catégorie
    category.items.forEach(item => {
        const menuItem = document.createElement('div');
        menuItem.className = 'menu-item';
        
        // Déterminer l'image à utiliser
        let imagePath = '';
        const itemNameLower = item.name.toLowerCase();
        
        if (itemNameLower.includes('tacos')) {
            if (itemNameLower.includes('poulet')) {
                imagePath = productImages.tacos.poulet;
            } else if (itemNameLower.includes('mixte')) {
                imagePath = productImages.tacos.mixte;
            } else {
                imagePath = productImages.tacos.viande;
            }
        } else if (itemNameLower.includes('sandwich')) {
            if (itemNameLower.includes('poulet')) {
                imagePath = productImages.sandwich.poulet;
            } else {
                imagePath = productImages.sandwich.kebab;
            }
        } else if (itemNameLower.includes('panini')) {
            if (itemNameLower.includes('kebab')) {
                imagePath = productImages.panini.kebab;
            } else {
                imagePath = productImages.panini.fromage;
            }
        } else if (itemNameLower.includes('tiramisu')) {
            imagePath = productImages.dessert.tiramisu;
        } else if (itemNameLower.includes('mousse') || itemNameLower.includes('chocolat')) {
            imagePath = productImages.dessert.mousse;
        } else if (itemNameLower.includes('tarte') || itemNameLower.includes('pomme')) {
            imagePath = productImages.dessert.tarte;
        } else if (itemNameLower.includes('coca') || itemNameLower.includes('cola')) {
            imagePath = productImages.boisson.coca;
        } else if (itemNameLower.includes('fanta')) {
            imagePath = productImages.boisson.fanta;
        } else if (itemNameLower.includes('sprite')) {
            imagePath = productImages.boisson.sprite;
        } else if (itemNameLower.includes('eau')) {
            imagePath = productImages.boisson.eau;
        } else {
            // Image par défaut selon la catégorie
            switch(categoryName) {
                case 'tacos':
                    imagePath = productImages.tacos.default;
                    break;
                case 'sandwichs':
                    imagePath = productImages.sandwich.default;
                    break;
                case 'paninis':
                    imagePath = productImages.panini.default;
                    break;
                case 'desserts':
                    imagePath = productImages.dessert.default;
                    break;
                case 'boissons':
                    imagePath = productImages.boisson.default;
                    break;
                default:
                    imagePath = productImages.tacos.default;
            }
        }
        
        menuItem.innerHTML = `
            <div class="menu-item-img">
                <img src="${imagePath}" alt="${item.name}">
            </div>
            <div class="menu-item-info">
                <div class="menu-item-header">
                    <h3 class="menu-item-title">${item.name}</h3>
                    <span class="menu-item-price">${item.price}</span>
                </div>
                <p class="menu-item-desc">${item.description || 'Préparé avec des ingrédients frais de qualité'}</p>
                <div class="menu-item-fresh">
                    <i class="fas fa-check-circle"></i> Ingrédients frais
                </div>
            </div>
        `;
        
        menuContainer.appendChild(menuItem);
    });
}

// Configurer les onglets du menu
function setupMenuTabs() {
    const tabButtons = document.querySelectorAll('.tab-btn');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Retirer la classe active de tous les boutons
            tabButtons.forEach(btn => btn.classList.remove('active'));
            
            // Ajouter la classe active au bouton cliqué
            this.classList.add('active');
            
            // Afficher la catégorie correspondante
            const category = this.getAttribute('data-category');
            displayMenuCategory(category);
        });
    });
}

// Configurer le bouton Uber Eats
function setupUberEatsButton(uberEatsUrl) {
    const uberEatsButton = document.querySelector('.uber-btn');
    
    if (uberEatsButton && uberEatsUrl) {
        uberEatsButton.href = uberEatsUrl;
        uberEatsButton.target = '_blank';
    } else if (uberEatsButton) {
        // URL par défaut si non disponible
        uberEatsButton.href = 'https://www.ubereats.com/fr/store/le-roi-tacos-saint-quentin-en-yvelines/e4r1pWIxQ_uAHKRUpUC5ig';
        uberEatsButton.target = '_blank';
    }
}

// Configurer le numéro de téléphone
function setupPhoneNumber(phoneNumber) {
    const phoneButton = document.querySelector('.btn-secondary');
    
    if (phoneButton && phoneNumber) {
        phoneButton.href = `tel:${phoneNumber}`;
    } else if (phoneButton) {
        // Numéro par défaut si non disponible
        phoneButton.href = 'tel:0130434016';
    }
}

// Configurer le menu mobile
function setupMobileMenu() {
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const nav = document.querySelector('nav');
    
    if (mobileMenuBtn && nav) {
        mobileMenuBtn.addEventListener('click', function() {
            nav.classList.toggle('active');
            
            // Changer l'icône
            const icon = this.querySelector('i');
            if (icon.classList.contains('fa-bars')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-times');
            } else {
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
    }
}
