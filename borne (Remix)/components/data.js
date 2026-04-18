// Menu data for Grill House borne
window.MENU = {
  categories: [
    { id: "burgers", name: "Burgers", emoji: "🍔", hue: 28 },
    { id: "menus",   name: "Menus",   emoji: "🍟", hue: 42 },
    { id: "chicken", name: "Poulet",  emoji: "🍗", hue: 20 },
    { id: "tacos",   name: "Tacos",   emoji: "🌮", hue: 40 },
    { id: "sides",   name: "Accomp.", emoji: "🥔", hue: 50 },
    { id: "salads",  name: "Salades", emoji: "🥗", hue: 130 },
    { id: "kids",    name: "Enfant",  emoji: "🧒", hue: 340 },
    { id: "desserts",name: "Desserts",emoji: "🍰", hue: 340 },
    { id: "drinks",  name: "Boissons",emoji: "🥤", hue: 200 },
    { id: "sauces",  name: "Sauces",  emoji: "🥫", hue: 10 },
  ],
  items: {
    burgers: [
      { id: "smash", name: "Smash Signature", desc: "Double steak haché, cheddar fondu, oignons caramélisés, sauce maison", price: 10.90, kcal: 780, tag: "best", hue: 20 },
      { id: "bbq",   name: "BBQ Bacon",       desc: "Steak 150g, bacon fumé, cheddar, sauce BBQ, crispy onions", price: 11.50, kcal: 820, tag: "new", hue: 25 },
      { id: "double",name: "Double Cheese",   desc: "Deux steaks, triple cheddar, pickles, sauce cheese", price: 9.90,  kcal: 740, hue: 28 },
      { id: "chkn",  name: "Chicken Crispy",  desc: "Poulet pané, salade, tomate, sauce samouraï", price: 8.90,  kcal: 620, hue: 30 },
      { id: "vege",  name: "Veggie Garden",   desc: "Steak légumes, avocat, tomate, sauce herbes", price: 8.50,  kcal: 510, tag: "veg", hue: 100 },
      { id: "spicy", name: "Spicy Jalapeño",  desc: "Steak, jalapeños frais, pepper jack, sauce piquante", price: 10.50, kcal: 790, tag: "hot", hue: 10 },
    ],
    menus: [
      { id: "m-smash",  name: "Menu Smash",       desc: "Smash Signature + Frites + Boisson", price: 13.90, kcal: 1180, tag: "best", hue: 24 },
      { id: "m-bbq",    name: "Menu BBQ",         desc: "BBQ Bacon + Frites XL + Boisson", price: 14.50, kcal: 1240, hue: 22 },
      { id: "m-double", name: "Menu Double",      desc: "Double Cheese + Frites + Boisson", price: 12.90, kcal: 1140, hue: 28 },
      { id: "m-chkn",   name: "Menu Crispy",      desc: "Chicken Crispy + Tenders + Boisson", price: 12.50, kcal: 1020, hue: 30 },
    ],
    chicken: [
      { id: "ten-6", name: "6 Tenders",       desc: "Filets de poulet panés, sauce au choix", price: 6.90, kcal: 420, hue: 35 },
      { id: "ten-12",name: "12 Tenders",      desc: "12 filets + 2 sauces", price: 11.90, kcal: 820, tag: "best", hue: 35 },
      { id: "wings", name: "Wings Buffalo",   desc: "8 ailes marinées, sauce buffalo", price: 8.50, kcal: 580, hue: 12 },
      { id: "bucket",name: "Bucket Famille",  desc: "16 pièces mélangées", price: 19.90, kcal: 1640, hue: 30 },
    ],
    tacos: [
      { id: "tac-1", name: "Tacos M", desc: "1 viande au choix, fromage, frites dedans, sauce", price: 7.90, kcal: 680, hue: 40 },
      { id: "tac-2", name: "Tacos L", desc: "2 viandes, double fromage", price: 9.90, kcal: 940, hue: 40 },
      { id: "tac-3", name: "Tacos XL",desc: "3 viandes, triple fromage", price: 11.90, kcal: 1240, tag: "hot", hue: 40 },
    ],
    sides: [
      { id: "f-m", name: "Frites M", desc: "Frites fraîches maison", price: 2.90, kcal: 320, hue: 48 },
      { id: "f-l", name: "Frites L", desc: "Grande portion", price: 3.90, kcal: 480, hue: 48 },
      { id: "ched",name: "Cheddar Bites", desc: "Bouchées panées au cheddar", price: 4.50, kcal: 380, hue: 42 },
      { id: "ring",name: "Onion Rings",   desc: "Anneaux d'oignon panés", price: 3.90, kcal: 360, hue: 38 },
    ],
    salads: [
      { id: "s-c", name: "César Grillé",  desc: "Salade, poulet, parmesan, croûtons", price: 8.90, kcal: 440, tag: "veg", hue: 110 },
      { id: "s-a", name: "Avocat Quinoa", desc: "Quinoa, avocat, tomates confites", price: 9.50, kcal: 420, tag: "veg", hue: 120 },
    ],
    kids: [
      { id: "k-1", name: "Happy Kids Burger", desc: "Mini burger + frites + compote + jouet", price: 6.90, kcal: 520, hue: 28 },
      { id: "k-2", name: "Happy Kids Tenders",desc: "3 tenders + frites + compote + jouet", price: 6.90, kcal: 540, hue: 32 },
    ],
    desserts: [
      { id: "d-1", name: "Cookie Choco",  desc: "Cookie tiède pépites de chocolat", price: 2.50, kcal: 320, hue: 25 },
      { id: "d-2", name: "Brownie",       desc: "Brownie fondant", price: 3.50, kcal: 380, hue: 20 },
      { id: "d-3", name: "Sundae Caramel",desc: "Glace vanille, sauce caramel", price: 3.90, kcal: 340, hue: 40 },
      { id: "d-4", name: "Donut Glacé",   desc: "Donut rose glaçage", price: 2.90, kcal: 280, hue: 340 },
      { id: "d-5", name: "Milkshake",     desc: "Vanille, chocolat ou fraise", price: 4.50, kcal: 420, hue: 330 },
      { id: "d-6", name: "Tarte Pomme",   desc: "Chaussons pomme tièdes", price: 2.50, kcal: 260, hue: 40 },
    ],
    drinks: [
      { id: "dr-1", name: "Coca-Cola",      desc: "33cl", price: 2.50, hue: 5 },
      { id: "dr-2", name: "Coca Zéro",      desc: "33cl", price: 2.50, hue: 5 },
      { id: "dr-3", name: "Sprite",         desc: "33cl", price: 2.50, hue: 95 },
      { id: "dr-4", name: "Fanta Orange",   desc: "33cl", price: 2.50, hue: 30 },
      { id: "dr-5", name: "Eau plate",      desc: "50cl", price: 1.90, hue: 190 },
      { id: "dr-6", name: "Ice Tea",        desc: "33cl", price: 2.50, hue: 35 },
    ],
    sauces: [
      { id: "sc-1", name: "Algérienne", price: 0.50, hue: 20 },
      { id: "sc-2", name: "Samouraï",   price: 0.50, hue: 10 },
      { id: "sc-3", name: "Barbecue",   price: 0.50, hue: 25 },
      { id: "sc-4", name: "Ketchup",    price: 0.50, hue: 0 },
      { id: "sc-5", name: "Mayo",       price: 0.50, hue: 50 },
      { id: "sc-6", name: "Curry",      price: 0.50, hue: 45 },
    ]
  }
};

// Wizard options (for burger/menu configuration)
window.WIZARD_STEPS = {
  burgers: [
    { id: "size",   title: "Choisissez la taille",        required:true, min:1, max:1, options: [
      { id: "std", name: "Standard", extra: 0 },
      { id: "big", name: "Big (+25cl steak)", extra: 2.00 },
    ]},
    { id: "cook",   title: "Cuisson du steak",            required:true, min:1, max:1, options: [
      { id: "r",   name: "Saignant", extra: 0 },
      { id: "m",   name: "À point",  extra: 0 },
      { id: "w",   name: "Bien cuit", extra: 0 },
    ]},
    { id: "extras", title: "Suppléments",                 required:false, min:0, max:4, options: [
      { id: "bac",  name: "Bacon fumé",  extra: 1.50 },
      { id: "che",  name: "Cheddar supplémentaire", extra: 1.00 },
      { id: "avo",  name: "Avocat",      extra: 1.50 },
      { id: "oni",  name: "Oignons caramélisés", extra: 0.80 },
      { id: "egg",  name: "Œuf",         extra: 1.20 },
      { id: "jal",  name: "Jalapeños",   extra: 0.80 },
    ]},
    { id: "sauce",  title: "Sauce dans le burger",        required:true, min:1, max:2, options: [
      { id: "mai",  name: "Maison",     extra: 0 },
      { id: "bbq",  name: "Barbecue",   extra: 0 },
      { id: "che",  name: "Cheese",     extra: 0 },
      { id: "sam",  name: "Samouraï",   extra: 0 },
      { id: "alg",  name: "Algérienne", extra: 0 },
    ]},
  ],
  menus: [
    { id: "burger", title: "Choisissez votre burger", required:true, min:1, max:1, options: [
      { id: "smash", name: "Smash Signature", extra: 0 },
      { id: "bbq",   name: "BBQ Bacon",       extra: 1.00 },
      { id: "double",name: "Double Cheese",   extra: 0 },
    ]},
    { id: "side", title: "Accompagnement", required:true, min:1, max:1, options: [
      { id: "fm", name: "Frites M",     extra: 0 },
      { id: "fl", name: "Frites L",     extra: 1.00 },
      { id: "on", name: "Onion Rings",  extra: 1.20 },
      { id: "ch", name: "Cheddar Bites",extra: 1.50 },
    ]},
    { id: "drink", title: "Boisson", required:true, min:1, max:1, options: [
      { id: "coca", name: "Coca-Cola", extra: 0 },
      { id: "zero", name: "Coca Zéro", extra: 0 },
      { id: "spri", name: "Sprite",    extra: 0 },
      { id: "fant", name: "Fanta",     extra: 0 },
      { id: "ice",  name: "Ice Tea",   extra: 0 },
      { id: "eau",  name: "Eau",       extra: 0 },
    ]},
  ]
};
