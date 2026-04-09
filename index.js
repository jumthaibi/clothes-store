const t_shirts = document.getElementById("five");

for(let i = 1; i<= 20 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0 && i!=8){
        product.innerHTML += `
        <img class="element-img" src="2-t-shirts/t-shirt${i}.jfif">
        <h5>t-shirt(${i}) </h5>
        <h4>4.05$</h4>
        <h5>size : 
        <select id="sizeID${i}">
            <option value="">none</option>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('t-shirt(${i})','sizeID${i}',4.05)">
            add to basket
        </button>`;
        t_shirts.appendChild(product);
    }
    else if(i%5 == 0){
        product.innerHTML += `
        <img class="element-img" src="2-t-shirts/t-shirt${i}.jfif">
        <h5>t-shirt(${i}) </h5>
        <h4>3.99$</h4>
        <h5>size : 
        <select id="sizeID${i}">
            <option value="">none</option>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('t-shirt(${i})','sizeID${i}',3.99)">
            add to basket
        </button>`;
        t_shirts.appendChild(product);
    }else if(i==8){
        product.innerHTML += `
        <img class="element-img" src="2-t-shirts/t-shirt${i}.jfif">
        <h5>t-shirt(${i}) </h5>
        <h4>2.45$</h4>
        <h5>size : 
        <select id="sizeID${i}">
            <option value="">none</option>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('t-shirt(${i})','sizeID${i}',2.45)">
            add to basket
        </button>`;
        t_shirts.appendChild(product);
    }
    else{
        product.innerHTML += `
        <img class="element-img" src="2-t-shirts/t-shirt${i}.jfif">
        <h5>t-shirt(${i}) </h5>
        <h4>2.99$</h4>
        <h5>size : 
        <select id="sizeID${i}">
            <option value="">none</option>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('t-shirt(${i})','sizeID${i}',2.99)">
            add to basket
        </button>`;
        t_shirts.appendChild(product);
    }
    
}//end of for loop

let basket = {};
let total_price = 0;
let basketPage = document.getElementById("ten");
let cart = document.getElementById("cart-count");
let cart_count = 0;

function add_to_basket(product,sizeID,price){
    let size = document.getElementById(sizeID).value;
    if(size == ""){
        alert("please enter a size before adding the product...");
        return;
    }


    cart_count += 1;
    cart.innerHTML = cart_count;

    let product_info = product + " - " + size;
    if(basket[product_info]){
        basket[product_info].count += 1;
    }
    else{
        basket[product_info] = {
            count : 1,
            price : price
        }
    }

    total_price += price;

    basketPage.innerHTML = "<h2>your basket: </h2>";
    for(let product in basket){
        basketPage.innerHTML += `<h4>${product} has been added ${basket[product].count} times
        <button class="btn" onclick="remove_from_basket('${product}')"> -1 </button>
        <button class="btn" onclick="increase_item('${product}')"> +1 </button>
        `;
    }
    basketPage.innerHTML += `<h3><span class="total">Total:</span> ${total_price.toFixed(2)}$ </h3>`;

}//end of the function

function remove_from_basket(product_name){

    basket[product_name].count -= 1;

    total_price -= basket[product_name].price;

    cart_count -= 1;
    cart.innerHTML = cart_count;

    if(basket[product_name].count === 0){
        delete basket[product_name];
    }

    renderBasket();
}

function renderBasket(){
    basketPage.innerHTML = "<h2>your basket: </h2>";
    for(let product in basket){
        basketPage.innerHTML += `<h4>${product} has been added ${basket[product].count} times
        <button class="btn" onclick="remove_from_basket('${product}')"> -1 </button>
        <button class="btn" onclick="increase_item('${product}')"> +1 </button>
        `;
    }
    basketPage.innerHTML += `<h3><span class="total">Total:</span> ${total_price.toFixed(2)}$ </h3>`;
}

function increase_item(product_name){
    basket[product_name].count += 1;

    total_price += basket[product_name].price;

    cart_count += 1;
    cart.innerHTML = cart_count;

    renderBasket();
}

const shoes = document.getElementById("six");
for(let i = 1; i <= 20 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i==4 | i==8 | i==10 | i==12){
        product.innerHTML += `
        <img class="element-img" src="3-shoes/shoes${i}.jfif">
        <h5>shoes(${i}) </h5>
        <h4>6.95$</h4>
        <h5>size : 
        <select id="sizeID${i+20}">
            <option value="">none</option>
            <option value="36">36</option>
            <option value="37">37</option>
            <option value="38">38</option>
            <option value="39">39</option>
            <option value="40">40</option>
            <option value="41">41</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('shoes(${i})','sizeID${i+20}',6.95)">
            add to basket
        </button>`;
        shoes.appendChild(product);
    }else if(i==1 | i==2 | i==6 | i==7 | i==19){
        product.innerHTML += `
        <img class="element-img" src="3-shoes/shoes${i}.jfif">
        <h5>shoes(${i}) </h5>
        <h4>7.95$</h4>
        <h5>size : 
        <select id="sizeID${i+20}">
            <option value="">none</option>
            <option value="36">36</option>
            <option value="37">37</option>
            <option value="38">38</option>
            <option value="39">39</option>
            <option value="40">40</option>
            <option value="41">41</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('shoes(${i})','sizeID${i+20}',7.95)">
            add to basket
        </button>`;
        shoes.appendChild(product);
    }else if(i==5 | i==9 | i==13 | i==16 | i==17 | i==18){
        product.innerHTML += `
        <img class="element-img" src="3-shoes/shoes${i}.jfif">
        <h5>shoes(${i}) </h5>
        <h4>8.95$</h4>
        <h5>size : 
        <select id="sizeID${i+20}">
            <option value="">none</option>
            <option value="36">36</option>
            <option value="37">37</option>
            <option value="38">38</option>
            <option value="39">39</option>
            <option value="40">40</option>
            <option value="41">41</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('shoes(${i})','sizeID${i+20}',8.95)">
            add to basket
        </button>`;
        shoes.appendChild(product);
    }else{
        product.innerHTML += `
        <img class="element-img" src="3-shoes/shoes${i}.jfif">
        <h5>shoes(${i}) </h5>
        <h4>9.95$</h4>
        <h5>size : 
        <select id="sizeID${i+20}">
            <option value="">none</option>
            <option value="36">36</option>
            <option value="37">37</option>
            <option value="38">38</option>
            <option value="39">39</option>
            <option value="40">40</option>
            <option value="41">41</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('shoes(${i})','sizeID${i+20}',9.95)">
            add to basket
        </button>`;
        shoes.appendChild(product);
    }
}

const winter = document.getElementById("seven");
for(let i = 1 ; i <= 8;i++){
    const product = document.createElement("div");
    product.className = "element";

        if(i==6 || i==4 || i==1){
            product.innerHTML += `
            <img class="element-img" src="4-winter/blouse${i}.jfif">
            <h5>blouse(${i}) </h5>
            <h4>5.99$</h4>
            <h5>size : 
            <select id="sizeID${i+40}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('blouse(${i})','sizeID${i+40}',5.99)">
                add to basket
            </button>`;
            winter.appendChild(product);
        }
        else{
            product.innerHTML += `
            <img class="element-img" src="4-winter/blouse${i}.jfif">
            <h5>blouse(${i}) </h5>
            <h4>4.95$</h4>
            <h5>size : 
            <select id="sizeID${i+40}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('blouse(${i})','sizeID${i+40}',4.95)">
                add to basket
            </button>`;
            winter.appendChild(product);
        }
}

for(let i=1 ; i<=4 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i==4){
        product.innerHTML += `
            <img class="element-img" src="4-winter/hat1.jfif">
            <h5>hat(1) </h5>
            <h4>3.95$</h4>
            <h5>size : 
            <select id="sizeID49">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('hat(1)','sizeID49',4.45)">
                add to basket
            </button>`;
            winter.appendChild(product);
    }
    else{
        product.innerHTML += `
            <img class="element-img" src="4-winter/ears cover${i}.jfif">
            <h5>ears cover(${i}) </h5>
            <h4>3.95$</h4>
            <h5>size : 
            <select id="sizeID${i+48}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('ears cover(${i})','sizeID${i+48}',3.95)">
                add to basket
            </button>`;
            winter.appendChild(product);
    } 
}

for(let i=1 ; i<= 14 ; i++){
    const product = document.createElement("div");
    product.className = "element";
    if(i%2 === 0 ){
        product.innerHTML += `
            <img class="element-img" src="4-winter/jacket${i}.jfif">
            <h5>jacket(${i}) </h5>
            <h4>10.95$</h4>
            <h5>size : 
            <select id="sizeID${i+52}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('jacket(${i})','sizeID${i+52}',10.95)">
                add to basket
            </button>`;
            winter.appendChild(product);
    }
    else{
        product.innerHTML += `
            <img class="element-img" src="4-winter/jacket${i}.jfif">
            <h5>jacket(${i}) </h5>
            <h4>11.45$</h4>
            <h5>size : 
            <select id="sizeID${i+52}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('jacket(${i})','sizeID${i+52}',11.45)">
                add to basket
            </button>`;
            winter.appendChild(product);
    }
}

for(let i=1 ; i<= 7 ; i++){
    const product = document.createElement("div");
    product.className = "element";
    if(i%2 === 0 ){
        product.innerHTML += `
            <img class="element-img" src="4-winter/scarf${i}.jfif">
            <h5>scarf(${i}) </h5>
            <h4>4.45$</h4>
            <h5>size : 
            <select id="sizeID${i+66}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('scarf(${i})','sizeID${i+66}',4.45)">
                add to basket
            </button>`;
            winter.appendChild(product);
    }
    else{
        product.innerHTML += `
            <img class="element-img" src="4-winter/scarf${i}.jfif">
            <h5>scarf(${i}) </h5>
            <h4>4.45$</h4>
            <h5>size : 
            <select id="sizeID${i+66}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('scarf(${i})','sizeID${i+66}',4.45)">
                add to basket
            </button>`;
            winter.appendChild(product);
    }
}


const summer = document.getElementById("eight");
for(let i = 1; i<=6 ;i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0){
        product.innerHTML += `
            <img class="element-img" src="5-summer/dress${i}.jfif">
            <h5>dress(${i}) </h5>
            <h4>6.45$</h4>
            <h5>size : 
            <select id="sizeID${i+73}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('dress(${i})','sizeID${i+73}',6.45)">
                add to basket
            </button>`;
            summer.appendChild(product);
    }
    else{
        product.innerHTML += `
        <img class="element-img" src="5-summer/dress${i}.jfif">
        <h5>dress(${i}) </h5>
        <h4>7.25$</h4>
        <h5>size : 
        <select id="sizeID${i+73}">
            <option value="">none</option>
            <option value="small">small</option>
            <option value="medium">medium</option>
            <option value="large">large</option>
        </select>
        <br>
        <button id="add-button" onclick="add_to_basket('dress(${i})','sizeID${i+73}',7.25)">
            add to basket
        </button>`;
        summer.appendChild(product);
    }
}

for(let i=1 ; i<=8 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0){
        product.innerHTML += `
            <img class="element-img" src="5-summer/jeans${i}.jfif">
            <h5>jeans(${i}) </h5>
            <h4>9.55$</h4>
            <h5>size : 
            <select id="sizeID${i+79}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('jeans(${i})','sizeID${i+79}',9.55)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }else{
        product.innerHTML += `
            <img class="element-img" src="5-summer/jeans${i}.jfif">
            <h5>jeans(${i}) </h5>
            <h4>8.35$</h4>
            <h5>size : 
            <select id="sizeID${i+79}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('jeans(${i})','sizeID${i+79}',8.35)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }
    
}
    
for(let i=1 ; i<=8 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0){
        product.innerHTML += `
            <img class="element-img" src="5-summer/skirt${i}.jfif">
            <h5>skirt(${i}) </h5>
            <h4>7.75$</h4>
            <h5>size : 
            <select id="sizeID${i+87}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('skirt(${i})','sizeID${i+87}',7.75)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }else{
        product.innerHTML += `
            <img class="element-img" src="5-summer/skirt${i}.jfif">
            <h5>skirt(${i}) </h5>
            <h4>6.95$</h4>
            <h5>size : 
            <select id="sizeID${i+87}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('skirt(${i})','sizeID${i+87}',6.95)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }
    
}


for(let i=1 ; i<=8 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0){
        product.innerHTML += `
            <img class="element-img" src="5-summer/top${i}.jfif">
            <h5>top(${i}) </h5>
            <h4>5.95$</h4>
            <h5>size : 
            <select id="sizeID${i+95}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('top(${i})','sizeID${i+95}',5.95)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }else{
        product.innerHTML += `
            <img class="element-img" src="5-summer/top${i}.jfif">
            <h5>top(${i}) </h5>
            <h4>5.45$</h4>
            <h5>size : 
            <select id="sizeID${i+95}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('top(${i})','sizeID${i+95}',5.45)">
                add to basket
            </button>`;
        summer.appendChild(product);
    }
    
}

const pajamas = document.getElementById("nine");
for(let i=1 ; i<=5 ; i++){
    const product = document.createElement("div");
    product.className = "element";

    if(i%2 == 0){
        product.innerHTML += `
            <img class="element-img" src="6-pajamas/pajama${i}.jfif">
            <h5>pajama(${i}) </h5>
            <h4>7.95$</h4>
            <h5>size : 
            <select id="sizeID${i+103}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('top(${i})','sizeID${i+103}',7.95)">
                add to basket
            </button>`;
        pajamas.appendChild(product);
    }else{
        product.innerHTML += `
            <img class="element-img" src="6-pajamas/pajama${i}.jfif">
            <h5>pajama(${i}) </h5>
            <h4>8.25$</h4>
            <h5>size : 
            <select id="sizeID${i+103}">
                <option value="">none</option>
                <option value="small">small</option>
                <option value="medium">medium</option>
                <option value="large">large</option>
            </select>
            <br>
            <button id="add-button" onclick="add_to_basket('top(${i})','sizeID${i+103}',8.25)">
                add to basket
            </button>`;
        pajamas.appendChild(product);
    }
    
}

function basket_format(basket){
    let result = "";
    for(let item in basket){
        let product = basket[item];
        result += `${item} : x${product.count}\n`;
    }
    return result;
}

let order_btn = document.getElementById("order-btn");
order_btn.addEventListener("click",()=>{
    let customer_name = document.getElementById("customer_name");
    let phone_number = document.getElementById("phone_number");
    let location = document.getElementById("location");

    if(customer_name.value == "" || phone_number.value == "" || location.value == ""){
        alert("Please fill all fields.");
        return;
    }

    if(total_price == 0){
        alert("Your basket is empty!");
        return;
    }

    emailjs.init("MAhwKHJbq_8JNZg9l");

    let templateParams = {
        name: customer_name.value,
        message: `phone number : ${phone_number.value} \n
                location : ${location.value} \n
                basket : \n${basket_format(basket)} \n
                total price : ${total_price.toFixed(2)}$`,
        title: "new order",
        time: new Date().toLocaleString()
    };
    
    emailjs.send("service_puilcip", "template_3794cmf", templateParams)
    .then(function(response) {
        console.log("SUCCESS!", response.status, response.text);
        alert("Your order has been sent successfully!");
    }, function(error) {
        console.log("FAILED...", error);
        alert("Failed to send your order. Please try again.");
    });
});


    