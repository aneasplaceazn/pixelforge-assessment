/** Format a server decimal without rounding or floating-point conversion. */
export function money(value: string): string {
    return (
        '$' +
        (value.includes('.')
            ? value.replace(/0+$/, '').replace(/\.$/, '.00')
            : value)
    );
}
