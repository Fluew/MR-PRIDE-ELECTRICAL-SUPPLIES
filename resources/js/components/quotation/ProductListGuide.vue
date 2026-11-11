<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Shown when the client clicks "Upload product list", before the file picker opens: the two
 * columns we read, a sample table and tips for readable files.
 */
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ continue: [] }>();

defineProps<{ maxFiles: number; maxFileMb: number }>();

const headers = [
    {
        name: 'Description',
        examples: 'Description, Desc, Product, Item, Material',
    },
    { name: 'Quantity', examples: 'Quantity, Qty, Qnty, Quant' },
];

const sample = [
    ['LED Bulb 12W', '10'],
    ['Electrical Wire 2.0mm²', '50'],
    ['PVC Pipe 1/2 inch', '20'],
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[calc(100dvh-2rem)] overflow-y-auto rounded-xl p-5 sm:max-w-xl sm:p-6"
            data-test="product-list-guide"
        >
            <DialogHeader>
                <DialogTitle class="text-lg font-semibold text-gray-900"
                    >Product List Upload Guide</DialogTitle
                >
                <DialogDescription class="text-sm leading-6 text-gray-600">
                    Please make sure your document contains the following two
                    columns so we can accurately identify your requested
                    products.
                </DialogDescription>
            </DialogHeader>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="w-full text-left text-xs sm:text-sm">
                    <caption class="sr-only">
                        Required columns and the header names we accept
                    </caption>
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Required header
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                Accepted examples
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="h in headers" :key="h.name">
                            <th
                                scope="row"
                                class="px-3 py-2 font-semibold text-gray-900"
                            >
                                {{ h.name }}
                            </th>
                            <td class="px-3 py-2 text-gray-600">
                                {{ h.examples }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <p class="mb-1.5 text-xs font-medium text-gray-500">
                    Sample Product List
                </p>
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <caption class="sr-only">
                            A sample product list
                        </caption>
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium">
                                    Description
                                </th>
                                <th scope="col" class="px-3 py-2 font-medium">
                                    Quantity
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-800">
                            <tr v-for="row in sample" :key="row[0]">
                                <td class="px-3 py-2">{{ row[0] }}</td>
                                <td class="px-3 py-2">{{ row[1] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <ul
                class="list-disc space-y-1 pl-5 text-xs leading-5 text-gray-600 sm:text-sm"
            >
                <li>Use clear and readable handwriting or printed text.</li>
                <li>Keep product descriptions and quantities aligned.</li>
                <li>Make sure both required headers are present.</li>
                <li>Avoid blurry, cropped, or heavily shadowed images.</li>
                <li>
                    Do not upload product photographs without a written product
                    list.
                </li>
                <li>Accepted formats: PDF, PNG, JPG, JPEG, XLS, XLSX, CSV.</li>
                <li>Maximum {{ maxFiles }} files, {{ maxFileMb }} MB each.</li>
            </ul>

            <DialogFooter>
                <button
                    type="button"
                    class="secondary-button"
                    @click="open = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="brand-button"
                    data-test="product-list-guide-continue"
                    @click="emit('continue')"
                >
                    Continue to Upload
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
