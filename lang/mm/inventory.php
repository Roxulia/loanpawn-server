<?php

return [
    // Supported internal issue provenance.
    'issue_context_invalid' => 'စာရင်းထုတ်ယူမှု၏ ရင်းမြစ်သည် ခွင့်ပြုထားသော လုပ်ဆောင်မှုနှင့် မကိုက်ညီပါ။',
    // Quantity format, precision, and range validation.
    'quantity_invalid' => 'အရေအတွက်ကို မှန်ကန်သော ဂဏန်းဖြင့် ထည့်ပါ။',
    'quantity_positive' => 'အရေအတွက်ကို သုညထက်ပို၍ ထည့်ပါ။',
    'quantity_precision' => 'အရေအတွက်ကို ဒဿမ ၃ နေရာအထိသာ ထည့်နိုင်ပါသည်။',
    'quantity_out_of_range' => 'အရေအတွက်သည် လက်ခံနိုင်သော သတ်မှတ်ပမာဏထက် ကျော်လွန်နေပါသည်။',

    // Tracking-mode quantity requirements.
    'unique_quantity' => 'တစ်ခုတည်းရှိသောပစ္စည်း၏ အရေအတွက်ကို ၁ ဟုသာ ထည့်ပါ။',
    'serialized_quantity' => 'Serial နံပါတ်ပါပစ္စည်းများ၏ အရေအတွက်ကို ကိန်းပြည့်ဖြင့် ထည့်ပါ။',

    // Inventory tracking, reference, location, and uniqueness validation.
    'tracking_invalid' => 'ပစ္စည်းခြေရာခံစနစ်ကို မှန်ကန်စွာ ရွေးချယ်ပါ။',
    'catalog_unavailable' => 'ရွေးချယ်ထားသော ကတ်တလောက်ပစ္စည်းကို အသုံးမပြုနိုင်ပါ။',
    'tracking_mismatch' => 'ခြေရာခံစနစ်သည် ရွေးချယ်ထားသောပစ္စည်းနှင့် ကိုက်ညီရပါမည်။',
    'unit_mismatch' => 'ယူနစ်သည် ရွေးချယ်ထားသောပစ္စည်းနှင့် ကိုက်ညီရပါမည်။',
    'unit_unavailable' => 'ရွေးချယ်ထားသော တိုင်းတာယူနစ်ကို အသုံးမပြုနိုင်ပါ။',
    'name_required' => 'ပစ္စည်းအမည်ကို စာလုံး ၁၈၀ ထက်မပိုဘဲ ထည့်ပါ။',
    'identifiers_not_allowed' => 'Serial ပစ္စည်းများအတွက်သာ သီးခြားအမှတ်အသား ထည့်နိုင်ပါသည်။',
    'identifier_count' => 'Serial ပစ္စည်းတစ်ခုစီအတွက် အမှတ်အသားတစ်ခုစီ ထည့်ပါ။',
    'identifier_invalid' => 'Serial အမှတ်အသားကို စာလုံး ၁၂၀ ထက်မပိုဘဲ မှန်ကန်စွာ ထည့်ပါ။',
    'identifier_duplicate' => 'Serial အမှတ်အသားများ မထပ်ရပါ။',
    'identifier_in_use' => 'Serial အမှတ်အသားကို အသုံးပြုထားပြီး ဖြစ်ပါသည်။',
    'location_unavailable' => 'ရွေးချယ်ထားသော ပစ္စည်းသိုလှောင်နေရာကို အသုံးမပြုနိုင်ပါ။',
    'location_not_sellable' => 'ရွေးချယ်ထားသော ပစ္စည်းသိုလှောင်နေရာမှ ရောင်းချခွင့် မရှိပါ။',
    'location_name_in_use' => 'ဤသိုလှောင်နေရာအမည်ကို အသုံးပြုထားပြီး ဖြစ်ပါသည်။',
    'unit_selection_invalid' => 'အရေအတွက်အလိုက် မထပ်သော Serial ပစ္စည်းများကို ရွေးချယ်ပါ။ အခြားခြေရာခံစနစ်များအတွက် Serial ပစ္စည်း မရွေးချယ်ရပါ။',
    'item_unavailable' => 'လက်ရှိဆိုင်တွင် ရွေးချယ်ထားသောပစ္စည်းကို အသုံးမပြုနိုင်ပါ။',

    // Monthly checkpoint boundary validation.
    'summary_month_invalid' => 'ပစ္စည်းအနှစ်ချုပ်လကို YYYY-MM ပုံစံဖြင့် မှန်ကန်စွာ ထည့်ပါ။',
    'summary_month_incomplete' => 'ပြီးဆုံးပြီးသောလများအတွက်သာ ပစ္စည်းအနှစ်ချုပ် ပြုလုပ်နိုင်ပါသည်။',

    // Named resource errors and pre-write name validation.
    'item_not_found' => 'တောင်းဆိုထားသော ပစ္စည်းကို ရှာမတွေ့ပါ။',
    'location_not_found' => 'တောင်းဆိုထားသော ပစ္စည်းသိုလှောင်နေရာကို ရှာမတွေ့ပါ။',
    'location_name_invalid' => 'သိုလှောင်နေရာအမည်ကို စာလုံး ၁၂၀ ထက်မပိုဘဲ ထည့်ပါ။',
    // Current locking selection, exact availability, and reservation binding.
    'lock_selection_invalid' => 'ကုန်လက်ကျန်လုပ်ငန်းအတွက် မှန်ကန်သော ပစ္စည်းနှင့် နေရာများကို ရွေးချယ်ပါ။',
    'reservation_unavailable' => 'ကုန်လက်ကျန်ကြိုတင်သတ်မှတ်မှု မရှိပါ၊ အသုံးမပြုနိုင်ပါ၊ မလုံလောက်ပါ သို့မဟုတ် ယခုလုပ်ငန်းနှင့် မကိုက်ညီပါ။',
    'serialized_unavailable' => 'ရွေးချယ်ထားသော Serial ပစ္စည်းကို အသုံးမပြုနိုင်ပါ၊ အခြားနေရာတွင်ရှိပါ သို့မဟုတ် အခြားလုပ်ငန်းအတွက် ကြိုတင်သတ်မှတ်ထားပါသည်။',
    'stock_inconsistent' => 'ကုန်လက်ကျန်အရေအတွက် သို့မဟုတ် ကြိုတင်သတ်မှတ်ထားသော ပစ္စည်းစာရင်း မကိုက်ညီပါ။',
    'stock_insufficient' => 'ဤနေရာတွင် အသုံးပြုနိုင်သော ကုန်လက်ကျန် မလုံလောက်ပါ။',
    // Append-only movement history protection.
    'movement_immutable' => 'ကုန်လက်ကျန်ရွှေ့ပြောင်းမှုမှတ်တမ်းကို ပြင်ဆင်ခြင်း သို့မဟုတ် ဖျက်ခြင်း မပြုနိုင်ပါ။ ပြင်ဆင်ရန် မှတ်တမ်းအသစ် ထည့်ပါ။',
    // Location creation validation for trusted service callers.
    'location_type_invalid' => 'သိုလှောင်နေရာအမျိုးအစားကို မှန်ကန်စွာ ရွေးချယ်ပါ။',
    // Location edit restrictions protect custody and outstanding reservations.
    'default_location_must_remain_active' => 'မူလသိုလှောင်နေရာကို အသုံးမပြုနိုင်အောင် ပိတ်ထား၍ မရပါ။',
    'location_has_active_reservations' => 'ဤနေရာတွင် ကြိုတင်သတ်မှတ်ထားသော ပစ္စည်းများ ရှိပါသည်။ နေရာကို ကန့်သတ်ခြင်းမပြုမီ ကြိုတင်သတ်မှတ်မှုများကို ဖြေရှင်းပါ။',
    'location_has_stock' => 'ဤနေရာတွင် ကုန်လက်ကျန် ရှိနေပါသည်။ နေရာကို ပိတ်မထားမီ ပစ္စည်းအားလုံးကို ရွှေ့ပြောင်းပါ သို့မဟုတ် ထုတ်ပေးပါ။',
    // UNIQUE receipt must not duplicate current physical custody.
    'unique_already_received' => 'ဤတစ်ခုတည်းသတ်မှတ်ထားသော ပစ္စည်းသည် ကုန်လက်ကျန်တွင် ရှိနေပြီးဖြစ်ပါသည်။',
];
